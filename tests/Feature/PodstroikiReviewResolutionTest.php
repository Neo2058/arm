<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\Mobile\PodstroikaApiController;
use App\Models\Podstroika;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\PodstroikaService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PodstroikiReviewResolutionTest extends TestCase
{
    use RefreshDatabase;

    private function prepare(): User
    {
        $this->travelTo(Carbon::parse('2026-11-06 12:00:00'));
        putenv('REDIS_PORT=9');

        if (!Schema::hasTable('arm_podstroika_limits')) {
            Schema::create('arm_podstroika_limits', function (Blueprint $t) {
                $t->string('tab_number');
                $t->string('for_month');
                $t->integer('max_approved');
            });
        }

        if (!Schema::hasTable('arm_podstroiki')) {
            Schema::create('arm_podstroiki', function (Blueprint $t) {
                $t->integer('id')->primary();
                $t->string('tab_number');
                $t->date('target_date');
                $t->string('route_code');
                $t->string('shift_code');
                $t->text('details');
                $t->string('status');
                $t->timestamps();
            });
        }

        $u = User::factory()->create(['role' => 'driver']);
        UserProfile::create(['user_id' => $u->id, 'tab_number' => '0321', 'column' => '15']);
        DB::table('arm_podstroika_limits')->insert(['tab_number' => '0321', 'for_month' => '2026-11', 'max_approved' => 10]);

        return $u;
    }

    /**
     * 1. Идемпотентность: один UUID с разным телом вызывает 409 Conflict,
     * а с тем же телом возвращает прежний результат без дублирования.
     */
    public function test_operation_id_idempotency_and_conflict_handling(): void
    {
        $u = $this->prepare();
        $uuid = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

        // Первый запрос успешен
        $a = PodstroikaService::createPodstroika($u, '2026-11-10', '01', '1', 'first', 'mobile', null, $uuid);
        $this->assertTrue($a['success']);
        $this->assertDatabaseCount('podstroikas', 1);

        // Повторный запрос с тем же UUID и тем же телом — возвращает прежний результат
        $repeat = PodstroikaService::createPodstroika($u, '2026-11-10', '01', '1', 'first', 'mobile', null, $uuid);
        $this->assertTrue($repeat['success']);
        $this->assertEquals($a['podstroika']['id'], $repeat['podstroika']['id']);
        $this->assertDatabaseCount('podstroikas', 1);

        // Запрос с тем же UUID, но другим телом (изменилась дата/детали) — 409 Conflict
        try {
            PodstroikaService::createPodstroika($u, '2026-12-11', '02', '2', 'changed', 'mobile', null, $uuid);
            $this->fail('Expected HttpException 409');
        } catch (HttpException $e) {
            $this->assertEquals(409, $e->getStatusCode());
            $this->assertStringContainsString('Ключ операции (operation_id) уже использован', $e->getMessage());
        }
    }

    /**
     * 2. Лимит подстроек: при лимите 10 разрешено подавать несколько заявок в один месяц.
     */
    public function test_two_requests_in_same_month_succeed_when_limit_allows(): void
    {
        $u = $this->prepare();

        $a = PodstroikaService::createPodstroika($u, '2026-11-10', '01', '1', 'first');
        $b = PodstroikaService::createPodstroika($u, '2026-11-11', '02', '2', 'second');

        $this->assertTrue($a['success']);
        $this->assertTrue($b['success']);
        $this->assertDatabaseCount('podstroikas', 2);

        $limit = PodstroikaService::getLimitStatus('0321', $u->id, '2026-11');
        $this->assertEquals(2, $limit['used']);
        $this->assertEquals(8, $limit['remaining']);
        $this->assertTrue($limit['can_submit']);
    }

    /**
     * 3. Зеркало и канонический источник: сбой записи в зеркало не подавляется тихо,
     * а откатывает транзакцию, исключая половинчатые записи.
     */
    public function test_mirror_failure_does_not_leave_orphaned_canonical_record(): void
    {
        $u = $this->prepare();
        DB::statement("CREATE TRIGGER reject_mirror BEFORE INSERT ON arm_podstroiki BEGIN SELECT RAISE(ABORT,'forced mirror failure'); END");

        try {
            PodstroikaService::createPodstroika($u, '2026-11-10', '01', '1', 'x', 'mobile', null, 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb');
            $this->fail('Expected mirror failure exception');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('forced mirror failure', $e->getMessage());
        }

        // Ни в podstroikas, ни в arm_podstroiki не осталось неконсистентных записей
        $this->assertDatabaseCount('podstroikas', 0);
        $this->assertDatabaseCount('arm_podstroiki', 0);
    }

    /**
     * 4. Безопасная отмена: отмена заявки не удаляет и не изменяет чужие строки с тем же ID,
     * а переводит статус в CANCELLED / cancelled.
     */
    public function test_cancel_does_not_touch_unrelated_worker_rows(): void
    {
        $u = $this->prepare();
        $p = Podstroika::create([
            'user_id' => $u->id,
            'for_month' => '2026-11-01',
            'target_date' => '2026-11-10',
            'route_code' => '01',
            'shift_code' => '1',
            'details' => 'own',
            'status' => 'pending',
        ]);

        // Чужая строка в зеркале с тем же ID и другим табельным
        DB::table('arm_podstroiki')->insert([
            'id' => $p->id,
            'tab_number' => '9999',
            'target_date' => '2026-11-11',
            'route_code' => '02',
            'shift_code' => '2',
            'details' => 'another worker',
            'status' => 'APPLIED',
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $r = PodstroikaService::cancelPodstroika($p->id, $u);
        $this->assertTrue($r['success']);

        // Чужая строка осталась нетронутой!
        $this->assertDatabaseCount('arm_podstroiki', 1);
        $mirror = DB::table('arm_podstroiki')->where('id', $p->id)->first();
        $this->assertEquals('9999', $mirror->tab_number);
        $this->assertEquals('APPLIED', $mirror->status);

        // Своя заявка отмечена как cancelled
        $fresh = Podstroika::find($p->id);
        $this->assertEquals('cancelled', $fresh->status);
    }

    /**
     * 5. Схема АТЗ и контракт версий: таблицы АТЗ созданы миграциями,
     * syncForNaryad передает version и server_id.
     */
    public function test_atz_schema_exists_and_sync_endpoint_transmits_version(): void
    {
        $this->assertTrue(Schema::hasTable('instructor_training_requests'));
        $this->assertTrue(Schema::hasTable('instructor_training_members'));
        $this->assertTrue(Schema::hasTable('instructor_training_operations'));

        $dispatcher = User::factory()->create(['role' => 'admin']);
        UserProfile::create(['user_id' => $dispatcher->id, 'tab_number' => '0001', 'column' => '01']);

        $instructor = User::factory()->create(['role' => 'instructor']);
        UserProfile::create(['user_id' => $instructor->id, 'tab_number' => '0438', 'column' => '15']);

        $driver = User::factory()->create(['role' => 'driver']);
        UserProfile::create(['user_id' => $driver->id, 'tab_number' => '0321', 'column' => '15']);

        $reqId = DB::table('instructor_training_requests')->insertGetId([
            'instructor_id' => $instructor->id,
            'column' => '15',
            'planning_month' => '2026-11-01',
            'shift_type' => 'А2',
            'participant_count' => 1,
            'version' => 3,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        DB::table('instructor_training_members')->insert([
            'request_id' => $reqId,
            'user_id' => $driver->id,
        ]);

        $request = Request::create('/api/mobile/podstroiki/sync-for-naryad?month=2026-11', 'GET');
        $request->setUserResolver(fn () => $dispatcher);

        $controller = new PodstroikaApiController();
        $response = $controller->syncForNaryad($request);

        $data = $response->getData(true);
        $this->assertTrue($data['success']);
        $this->assertNotEmpty($data['instructor_training_cards']);

        $card = $data['instructor_training_cards'][0];
        $this->assertEquals($reqId, $card['id']);
        $this->assertEquals($reqId, $card['server_id']);
        $this->assertEquals(3, $card['version']);
        $this->assertEquals('PENDING', $card['status']);
        $this->assertCount(1, $card['participants']);
        $this->assertEquals('0321', $card['participants'][0]['tab_number']);
    }

    /**
     * 6. Защита от частичного изменения при отказе отмены:
     * Если зеркало APPLIED, отказ возвращается ДО изменения основной заявки,
     * поэтому статус основной заявки остаётся 'pending' (не меняется на 'cancelled').
     */
    public function test_cancel_refusal_does_not_modify_canonical_or_mirror_status(): void
    {
        $u = $this->prepare();
        $p = Podstroika::create([
            'user_id' => $u->id,
            'for_month' => '2026-11-01',
            'target_date' => '2026-11-10',
            'route_code' => '01',
            'shift_code' => '1',
            'details' => 'own',
            'status' => 'pending',
        ]);
        DB::table('arm_podstroiki')->insert([
            'id' => $p->id,
            'tab_number' => '0321',
            'target_date' => '2026-11-10',
            'route_code' => '01',
            'shift_code' => '1',
            'details' => 'own',
            'status' => 'APPLIED',
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $r = PodstroikaService::cancelPodstroika($p->id, $u);
        $this->assertFalse($r['success']);
        $this->assertSame('Нельзя отменить заявку, которая уже занесена в наряд.', $r['message']);

        // Никакого частичного применения: основная заявка осталась pending!
        $this->assertSame('pending', $p->fresh()->status);
        $this->assertSame('APPLIED', DB::table('arm_podstroiki')->where('id', $p->id)->value('status'));
    }
}
