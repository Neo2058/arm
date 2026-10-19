<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckDeviceBinding;
use App\Http\Middleware\CheckDynamicBarrier;
use App\Models\ActionLog;
use App\Models\Naryad;
use App\Models\Podstroika;
use App\Models\TrainingMaterial;
use App\Models\TrainingTopic;
use App\Models\User;
use App\Models\WorkShift;
use App\Services\ClickHouseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UserActionCoverageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        ClickHouseService::fake();
        Storage::fake('s3');
        Http::fake();
        Mail::fake();
        $this->withoutMiddleware([
            CheckDeviceBinding::class,
            CheckDynamicBarrier::class,
        ]);
    }

    public function test_web_login_logout_and_failed_credentials(): void
    {
        $user = User::factory()->driver()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect();
        $this->assertAuthenticatedAs($user);
        $this->assertClickHouseAction('login', function (array $row) use ($user) {
            $details = json_decode($row['details'], true);

            return (int) $row['user_id'] === $user->id
                && ($details['via'] ?? null) === 'web';
        });

        $this->post('/logout')->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertClickHouseAction('logout', function (array $row) use ($user) {
            return (int) $row['user_id'] === $user->id;
        });

        ClickHouseService::fake();
        $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertSessionHasErrors('email');
        $this->assertClickHouseAction('login_failed', function (array $row) {
            $details = json_decode($row['details'], true);

            return ($details['reason'] ?? null) === 'credentials'
                && (int) $row['user_id'] === 0;
        });
    }

    public function test_inactive_web_login_is_failed_with_inactive_reason(): void
    {
        $user = User::factory()->driver()->create(['is_active' => false]);

        $this->from('/login')->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect();
        $this->assertGuest();
        $this->assertClickHouseAction('login_failed', function (array $row) {
            $details = json_decode($row['details'], true);

            return ($details['reason'] ?? null) === 'inactive';
        });
        $this->assertClickHouseMissing('login');
        $this->assertClickHouseMissing('logout');
    }

    public function test_mobile_login_and_token_logout(): void
    {
        $user = User::factory()->driver()->create();

        $login = $this->postJson('/api/mobile/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();

        $this->assertClickHouseAction('mobile.login', function (array $row) use ($user) {
            return (int) $row['user_id'] === $user->id;
        });

        $token = $login->json('token');
        $this->flushSession();
        $this->withToken($token)
            ->postJson('/api/mobile/logout')
            ->assertOk();

        $this->assertClickHouseAction('mobile.logout', function (array $row) use ($user) {
            $details = json_decode($row['details'], true);

            return (int) $row['user_id'] === $user->id
                && ($details['via'] ?? null) === 'token';
        });
    }

    public function test_inactive_mobile_login_does_not_log_logout(): void
    {
        $user = User::factory()->driver()->create(['is_active' => false]);

        $this->postJson('/api/mobile/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertForbidden();

        $this->assertClickHouseAction('login_failed', function (array $row) {
            $details = json_decode($row['details'], true);

            return ($details['reason'] ?? null) === 'inactive'
                && ($details['via'] ?? null) === 'mobile';
        });
        $this->assertClickHouseMissing('mobile.login');
        $this->assertClickHouseMissing('mobile.logout');
    }

    public function test_work_shift_save_is_logged(): void
    {
        $user = User::factory()->driver()->create();

        $this->actingAs($user)
            ->postJson('/api/work-shifts', [
                'shift_date' => '2026-10-01',
                'type' => 'work',
                'started_at' => '2026-10-01 08:00:00',
                'ended_at' => '2026-10-01 16:00:00',
                'break_duration' => 60,
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertTrue(
            WorkShift::query()->where('user_id', $user->id)->whereDate('shift_date', '2026-10-01')->exists()
        );
        $this->assertClickHouseAction('work_shift.saved', function (array $row) {
            $details = json_decode($row['details'], true);

            return ($details['shift_date'] ?? null) === '2026-10-01'
                && ($details['type'] ?? null) === 'work';
        });
    }

    public function test_podstroika_submit_and_status_update_are_logged(): void
    {
        $driver = User::factory()->driver()->create();
        $dispatcher = User::factory()->dispatcher()->create();
        $month = now()->addMonth()->startOfMonth()->format('Y-m-d');

        $this->actingAs($driver)
            ->post(route('podstroiki.store'), [
                'for_month' => $month,
                'details' => 'Нужна подстройка',
            ])
            ->assertRedirect(route('podstroiki.index'));

        $podstroika = Podstroika::query()->first();
        $this->assertNotNull($podstroika);
        $this->assertClickHouseAction('podstroika.submitted', function (array $row) use ($podstroika) {
            return (int) $row['resource_id'] === $podstroika->id;
        });

        $this->actingAs($dispatcher)
            ->post(route('podstroiki.update-status', $podstroika), [
                'status' => 'podstroeno',
            ])
            ->assertRedirect();

        $this->assertClickHouseAction('podstroika.status_updated', function (array $row) use ($podstroika) {
            $details = json_decode($row['details'], true);

            return (int) $row['resource_id'] === $podstroika->id
                && ($details['status'] ?? null) === 'podstroeno';
        });
    }

    public function test_training_comment_is_logged(): void
    {
        $user = User::factory()->driver()->create();
        $topic = TrainingTopic::create([
            'title' => 'Тема',
            'slug' => 'tema',
            'is_active' => true,
        ]);
        $material = TrainingMaterial::create([
            'training_topic_id' => $topic->id,
            'title' => 'Текст',
            'type' => 'text',
            'content' => 'Содержание',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->post(route('training.comment.store', $material), [
                'comment' => 'Понятно',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('action_logs', [
            'user_id' => $user->id,
            'action' => 'training_comment_added',
        ]);
        $this->assertDatabaseHas('training_material_comments', [
            'training_material_id' => $material->id,
            'comment' => 'Понятно',
        ]);
    }

    public function test_journal_todo_create_is_logged(): void
    {
        $instructor = User::factory()->instructor()->create();

        $this->actingAs($instructor)
            ->post(route('journal.todo.add'), [
                'title' => 'Проверить нормы',
            ])
            ->assertRedirect(route('journal.index'));

        $this->assertDatabaseHas('journal_tasks', [
            'user_id' => $instructor->id,
            'title' => 'Проверить нормы',
        ]);
        $this->assertClickHouseAction('journal.todo.created', function (array $row) {
            $details = json_decode($row['details'], true);

            return ($details['title'] ?? null) === 'Проверить нормы';
        });
    }

    public function test_bug_report_is_logged(): void
    {
        $user = User::factory()->driver()->create();

        $this->actingAs($user)
            ->postJson(route('bug.report.store'), [
                'description' => 'Кнопка не нажимается',
                'page_url' => '/mainMenu',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseHas('action_logs', [
            'user_id' => $user->id,
            'action' => 'bug_report_submitted',
        ]);
    }

    public function test_naryad_pdf_logs_initial_range_only(): void
    {
        $user = User::factory()->driver()->create();
        Storage::disk('s3')->put('naryads/full.pdf', '%PDF-1.4 coverage');
        $naryad = Naryad::create([
            'title' => 'Наряд',
            'file_path' => 'naryads/full.pdf',
            'naryad_date' => '2026-10-01',
            'allowed_roles' => null,
        ]);

        $this->actingAs($user)->get(route('naryady.pdf', $naryad))->assertOk();
        $this->assertDatabaseHas('action_logs', [
            'user_id' => $user->id,
            'action' => 'view_naryad_pdf',
        ]);

        ActionLog::query()->delete();
        $this->actingAs($user)
            ->withHeaders(['Range' => 'bytes=100-'])
            ->get(route('naryady.pdf', $naryad))
            ->assertOk();
        $this->assertDatabaseMissing('action_logs', [
            'action' => 'view_naryad_pdf',
        ]);

        $this->actingAs($user)
            ->withHeaders(['Range' => 'bytes=0-'])
            ->get(route('naryady.pdf', $naryad))
            ->assertOk();
        $this->assertDatabaseHas('action_logs', [
            'action' => 'view_naryad_pdf',
        ]);
    }

    /**
     * @param  callable(array<string, mixed>): bool  $callback
     */
    private function assertClickHouseAction(string $action, callable $callback): void
    {
        $rows = array_values(array_filter(
            ClickHouseService::recorded(),
            fn (array $row) => $row['action_type'] === $action
        ));
        $this->assertNotEmpty($rows, "Missing ClickHouse action [{$action}]");
        $this->assertTrue(
            collect($rows)->contains(fn (array $row) => $callback($row)),
            "ClickHouse action [{$action}] did not match the expected payload"
        );
    }

    private function assertClickHouseMissing(string $action): void
    {
        $actions = array_column(ClickHouseService::recorded(), 'action_type');
        $this->assertNotContains($action, $actions);
    }
}
