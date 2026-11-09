<?php

namespace Tests\Feature;

use App\Models\NaryadPodstroikaLimit;
use App\Models\Podstroika;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\ClickHouseService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MobilePodstroikiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-09 12:00:00'));
        putenv('REDIS_PORT=9');
        ClickHouseService::fake();
    }

    private function person(string $role, string $tab): User
    {
        $user = User::factory()->create(['role' => $role]);
        UserProfile::create(['user_id' => $user->id, 'tab_number' => $tab, 'column' => '15']);

        return $user;
    }

    private function asUser(User $user)
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($user->createToken('mobile')->plainTextToken);
    }

    private function payload(string $date, string $details = 'нужна подстройка', ?string $operationId = null): array
    {
        return [
            'target_date' => $date,
            'route_code' => '01',
            'shift_code' => '1',
            'details' => $details,
            'operation_id' => $operationId ?: (string) Str::uuid(),
        ];
    }

    public function test_guest_cannot_read_or_submit(): void
    {
        $this->getJson('/api/mobile/podstroiki')->assertUnauthorized();
        $this->postJson('/api/mobile/podstroiki', $this->payload('2026-11-10'))->assertUnauthorized();
        $this->getJson('/api/mobile/podstroiki/limits')->assertUnauthorized();
    }

    public function test_dispatcher_cannot_submit_for_self(): void
    {
        $dispatcher = $this->person('dispatcher', '0001');

        $this->asUser($dispatcher)
            ->postJson('/api/mobile/podstroiki', $this->payload('2026-11-10'))
            ->assertForbidden()
            ->assertJsonPath('success', false);

        $this->assertDatabaseCount('podstroikas', 0);
        $this->assertSame([], array_column(ClickHouseService::recorded(), 'action_type'));
    }

    public function test_driver_can_submit_within_limit_and_is_logged(): void
    {
        $driver = $this->person('driver', '0321');
        NaryadPodstroikaLimit::create([
            'user_id' => $driver->id,
            'for_month' => '2026-11-01',
            'max_approved' => 2,
        ]);

        $created = $this->asUser($driver)
            ->postJson('/api/mobile/podstroiki', $this->payload('2026-11-10', 'смена на 1'))
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('podstroika.shift_code', '1')
            ->json();

        $this->assertNotEmpty($created['podstroika']['id']);
        $this->assertFalse($created['limit']['unlimited']);
        $this->assertSame(1, $created['limit']['used']);

        $rows = array_values(array_filter(
            ClickHouseService::recorded(),
            fn (array $row) => $row['action_type'] === 'podstroika.submitted'
        ));
        $this->assertNotEmpty($rows);
        $details = json_decode($rows[0]['details'], true);
        $this->assertSame('2026-11-10', $details['target_date']);
        $this->assertSame('mobile', $details['source']);
        $this->assertSame((int) $created['podstroika']['id'], (int) $rows[0]['resource_id']);
    }

    public function test_driver_is_blocked_when_monthly_limit_is_exhausted(): void
    {
        $driver = $this->person('driver', '0321');

        $this->asUser($driver)
            ->postJson('/api/mobile/podstroiki', $this->payload('2026-11-10'))
            ->assertCreated();

        $this->asUser($driver)
            ->postJson('/api/mobile/podstroiki', $this->payload('2026-11-11'))
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertDatabaseCount('podstroikas', 1);
    }

    public function test_instructor_submits_beyond_dispatcher_count_limit(): void
    {
        $instructor = $this->person('instructor', '0438');
        NaryadPodstroikaLimit::create([
            'user_id' => $instructor->id,
            'for_month' => '2026-11-01',
            'max_approved' => 1,
        ]);

        $this->asUser($instructor)
            ->postJson('/api/mobile/podstroiki', $this->payload('2026-11-10', 'первая'))
            ->assertCreated();
        $this->asUser($instructor)
            ->postJson('/api/mobile/podstroiki', $this->payload('2026-11-11', 'вторая'))
            ->assertCreated();
        $this->asUser($instructor)
            ->postJson('/api/mobile/podstroiki', $this->payload('2026-11-12', 'третья'))
            ->assertCreated();

        $index = $this->asUser($instructor)
            ->getJson('/api/mobile/podstroiki?month=2026-11')
            ->assertOk()
            ->assertJsonPath('limit.unlimited', true)
            ->assertJsonPath('limit.can_submit', true)
            ->json();

        $this->assertCount(3, $index['podstroikas']);
        $this->assertSame(3, $index['limit']['used']);

        $this->asUser($instructor)
            ->getJson('/api/mobile/podstroiki/limits')
            ->assertOk()
            ->assertJsonPath('current_month.unlimited', true);

        $this->asUser($instructor)
            ->postJson('/api/mobile/podstroiki', $this->payload('2026-11-10', 'тот же день'))
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_same_operation_id_replays_without_a_duplicate(): void
    {
        $driver = $this->person('driver', '0321');
        NaryadPodstroikaLimit::create([
            'user_id' => $driver->id,
            'for_month' => '2026-11-01',
            'max_approved' => 2,
        ]);
        $uuid = (string) Str::uuid();
        $body = $this->payload('2026-11-10', 'повтор', $uuid);

        $first = $this->asUser($driver)->postJson('/api/mobile/podstroiki', $body)->assertCreated()->json();
        $repeat = $this->asUser($driver)->postJson('/api/mobile/podstroiki', $body)->assertCreated()->json();

        $this->assertSame($first['podstroika']['id'], $repeat['podstroika']['id']);
        $this->assertDatabaseCount('podstroikas', 1);
        $this->assertCount(1, array_filter(
            ClickHouseService::recorded(),
            fn (array $row) => $row['action_type'] === 'podstroika.submitted'
        ));
    }

    public function test_owner_can_cancel_pending_and_cannot_cancel_applied(): void
    {
        $driver = $this->person('driver', '0321');
        NaryadPodstroikaLimit::create([
            'user_id' => $driver->id,
            'for_month' => '2026-11-01',
            'max_approved' => 2,
        ]);

        $id = $this->asUser($driver)
            ->postJson('/api/mobile/podstroiki', $this->payload('2026-11-10'))
            ->assertCreated()
            ->json('podstroika.id');

        $this->asUser($driver)
            ->deleteJson('/api/mobile/podstroiki/'.$id)
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame('cancelled', Podstroika::find($id)?->status);
        $this->assertNotEmpty(array_filter(
            ClickHouseService::recorded(),
            fn (array $row) => $row['action_type'] === 'podstroika.cancelled'
        ));

        $applied = Podstroika::create([
            'user_id' => $driver->id,
            'for_month' => '2026-11-01',
            'target_date' => '2026-11-18',
            'route_code' => '02',
            'shift_code' => '2',
            'details' => 'уже в наряде',
            'status' => 'podstroeno',
            'source' => 'mobile',
        ]);

        $this->asUser($driver)
            ->deleteJson('/api/mobile/podstroiki/'.$applied->id)
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_cancelled_request_can_be_resubmitted_on_the_same_date(): void
    {
        $driver = $this->person('driver', '0321');

        $id = $this->asUser($driver)
            ->postJson('/api/mobile/podstroiki', $this->payload('2026-11-10', 'первая'))
            ->assertCreated()
            ->json('podstroika.id');

        $this->asUser($driver)
            ->deleteJson('/api/mobile/podstroiki/'.$id)
            ->assertOk();

        $again = $this->asUser($driver)
            ->postJson('/api/mobile/podstroiki', $this->payload('2026-11-10', 'повтор после отзыва'))
            ->assertCreated()
            ->json();

        $this->assertSame($id, $again['podstroika']['id']);
        $this->assertSame('PENDING', $again['podstroika']['status']);
        $this->assertDatabaseCount('podstroikas', 1);
        $this->assertSame('pending', Podstroika::find($id)?->status);
        $this->assertStringContainsString('повтор после отзыва', Podstroika::find($id)?->details);
    }

    public function test_driver_cannot_use_dispatcher_sync_endpoint(): void
    {
        $driver = $this->person('driver', '0321');

        $this->asUser($driver)
            ->getJson('/api/mobile/podstroiki/sync-for-naryad')
            ->assertForbidden();
    }

    public function test_store_requires_structured_fields(): void
    {
        $driver = $this->person('driver', '0321');

        $this->asUser($driver)
            ->postJson('/api/mobile/podstroiki', ['details' => 'без даты'])
            ->assertStatus(422);
    }
}
