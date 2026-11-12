<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckDeviceBinding;
use App\Http\Middleware\CheckDynamicBarrier;
use App\Models\FormularTask;
use App\Models\User;
use App\Models\UserFormularLog;
use App\Models\UserProfile;
use App\Services\ClickHouseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class FormularTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        ClickHouseService::fake();
    }

    private function person(string $role = 'driver', string $column = '15'): User
    {
        $user = User::factory()->create(['role' => $role]);
        UserProfile::create(['user_id' => $user->id, 'column' => $column]);

        return $user;
    }

    private function api(User $user)
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($user->createToken('mobile')->plainTextToken);
    }

    private function task()
    {
        return FormularTask::create(['title' => 'Проверка знаний', 'is_active' => true, 'category' => 'formular', 'sort_order' => 1]);
    }

    private function body(int $version = 0): array
    {
        return ['operation_id' => (string) Str::uuid(), 'version' => $version, 'entry_text' => 'Проверил исправность оборудования'];
    }

    public function test_guest_and_other_roles_cannot_access(): void
    {
        $this->getJson('/api/mobile/formular')->assertUnauthorized();
        foreach (['dispatcher', 'admin', 'student'] as $role) {
            $this->api($this->person($role))->getJson('/api/mobile/formular')->assertForbidden();
        }
        $this->api($this->person())->getJson('/api/mobile/formular/review')->assertForbidden();
    }

    public function test_submit_retry_collision_and_no_foreign_user_override(): void
    {
        $driver = $this->person();
        $other = $this->person();
        $task = $this->task();
        $body = $this->body();
        $body['user_id'] = $other->id;
        $first = $this->api($driver)->postJson('/api/mobile/formular/tasks/'.$task->id, $body)->assertOk()->assertJsonPath('log.user_id', $driver->id)->assertJsonPath('log.status', 'pending');
        $this->postJson('/api/mobile/formular/tasks/'.$task->id, $body)->assertOk()->assertExactJson($first->json());
        $this->postJson('/api/mobile/formular/tasks/'.$task->id, [...$body, 'entry_text' => 'другое'])->assertConflict();
        $this->postJson('/api/mobile/formular/tasks/'.$task->id, $this->body(1))->assertConflict();
        $this->assertDatabaseCount('user_formular_logs', 1);
        $this->assertDatabaseCount('formular_operations', 1);
        $this->getJson('/api/mobile/formular')->assertOk()->assertJsonPath('tasks.0.log.id', $first->json('log.id'));
        $this->api($other)->getJson('/api/mobile/formular')->assertOk()->assertJsonPath('tasks.0.log', null);
    }

    public function test_review_scope_stats_missing_records_and_filters(): void
    {
        $driver = $this->person();
        $other = $this->person('driver', '16');
        $instructor = $this->person('instructor');
        $task = $this->task();
        $this->api($driver)->postJson('/api/mobile/formular/tasks/'.$task->id, $this->body())->assertOk();
        $this->person();
        $this->api($instructor)->getJson('/api/mobile/formular/review')->assertOk()->assertJsonPath('stats.total', 2)->assertJsonPath('stats.pending', 1)->assertJsonPath('stats.not_submitted', 1)->assertJsonCount(2, 'crew');
        $this->getJson('/api/mobile/formular/review?status=not_submitted')->assertOk()->assertJsonCount(1, 'records');
        $this->getJson('/api/mobile/formular/review?user_id='.$other->id)->assertOk()->assertJsonCount(0, 'records')->assertJsonPath('stats.total', 0);
        $this->getJson('/api/mobile/formular/review?status=garbage')->assertUnprocessable();
    }

    public function test_return_resubmit_and_confirm_with_optimistic_lock(): void
    {
        $driver = $this->person();
        $instructor = $this->person('instructor');
        $task = $this->task();
        $id = $this->api($driver)->postJson('/api/mobile/formular/tasks/'.$task->id, $this->body())->assertOk()->json('log.id');
        $body = ['operation_id' => (string) Str::uuid(), 'version' => 1, 'decision' => 'returned', 'review_comment' => 'Уточните запись'];
        $this->api($instructor)->postJson('/api/mobile/formular/logs/'.$id.'/review', $body)->assertOk()->assertJsonPath('log.version', 2)->assertJsonPath('log.reviewed_by', $instructor->id);
        $this->postJson('/api/mobile/formular/logs/'.$id.'/review', $body)->assertOk()->assertJsonPath('log.version', 2);
        $this->api($driver)->postJson('/api/mobile/formular/tasks/'.$task->id, $this->body(2))->assertOk()->assertJsonPath('log.status', 'pending')->assertJsonPath('log.reviewed_by', null)->assertJsonPath('log.review_comment', null)->assertJsonPath('log.version', 3);
        $this->api($instructor)->postJson('/api/mobile/formular/logs/'.$id.'/review', ['operation_id' => (string) Str::uuid(), 'version' => 1, 'decision' => 'confirmed'])->assertConflict();
        $this->postJson('/api/mobile/formular/logs/'.$id.'/review', ['operation_id' => (string) Str::uuid(), 'version' => 3, 'decision' => 'confirmed'])->assertOk()->assertJsonPath('log.status', 'confirmed');
        $this->api($driver)->postJson('/api/mobile/formular/tasks/'.$task->id, $this->body(4))->assertConflict();
    }

    public function test_foreign_column_and_self_review_blocked_and_inactive_task_blocked(): void
    {
        $driver = $this->person();
        $task = $this->task();
        $id = $this->api($driver)->postJson('/api/mobile/formular/tasks/'.$task->id, $this->body())->json('log.id');
        $this->api($this->person('instructor', '16'))->postJson('/api/mobile/formular/logs/'.$id.'/review', ['operation_id' => (string) Str::uuid(), 'version' => 1, 'decision' => 'confirmed'])->assertNotFound();
        $this->api($driver)->postJson('/api/mobile/formular/logs/'.$id.'/review', ['operation_id' => (string) Str::uuid(), 'version' => 1, 'decision' => 'confirmed'])->assertForbidden();
        $instructor = $this->person('instructor');
        $id = $this->api($instructor)->postJson('/api/mobile/formular/tasks/'.$task->id, $this->body())->json('log.id');
        $this->postJson('/api/mobile/formular/logs/'.$id.'/review', ['operation_id' => (string) Str::uuid(), 'version' => 1, 'decision' => 'confirmed'])->assertNotFound();
        $task->update(['is_active' => false]);
        $this->api($driver)->postJson('/api/mobile/formular/tasks/'.$task->id, $this->body())->assertNotFound();
    }

    public function test_validation_no_column_deactivation_and_monitor_tokens(): void
    {
        $driver = $this->person();
        $task = $this->task();
        $this->api($driver)->postJson('/api/mobile/formular/tasks/'.$task->id, ['operation_id' => (string) Str::uuid(), 'version' => 0, 'entry_text' => ' '])->assertUnprocessable();
        $this->postJson('/api/mobile/formular/tasks/'.$task->id, ['entry_text' => 'text'])->assertUnprocessable();
        $driver->update(['is_active' => false]);
        $this->getJson('/api/mobile/formular')->assertForbidden();
        $instructor = $this->person('instructor', '');
        $this->api($instructor)->getJson('/api/mobile/formular/review')->assertUnprocessable();
        $this->app['auth']->forgetGuards();
        $this->withToken($instructor->createToken('monitor', ['monitor:read'])->plainTextToken)->getJson('/api/mobile/formular')->assertForbidden();
        $this->assertDatabaseCount('user_formular_logs', 0);
    }

    public function test_legacy_entries_are_pending_and_can_be_reviewed(): void
    {
        $driver = $this->person();
        $task = $this->task();
        $log = UserFormularLog::create(['user_id' => $driver->id, 'formular_task_id' => $task->id, 'completed_at' => now(), 'entry_text' => 'Старая запись']);
        $this->api($this->person('instructor'))->getJson('/api/mobile/formular/review')->assertOk()->assertJsonPath('stats.pending', 1);
        $this->postJson('/api/mobile/formular/logs/'.$log->id.'/review', ['operation_id' => (string) Str::uuid(), 'version' => 1, 'decision' => 'returned'])->assertUnprocessable();
    }

    public function test_audit_excludes_text_and_retry_does_not_duplicate(): void
    {
        $driver = $this->person();
        $instructor = $this->person('instructor');
        $task = $this->task();
        // Direct service with no test-owned outer transaction, callbacks run at actual commit in production.
        $id = $this->api($driver)->postJson('/api/mobile/formular/tasks/'.$task->id, $this->body())->json('log.id');
        $this->api($instructor)->postJson('/api/mobile/formular/logs/'.$id.'/review', ['operation_id' => (string) Str::uuid(), 'version' => 1, 'decision' => 'confirmed'])->assertOk();
        $this->assertCount(2, ClickHouseService::recorded());
        foreach (ClickHouseService::recorded() as $event) {
            $this->assertStringNotContainsString('Проверил', json_encode($event, JSON_UNESCAPED_UNICODE));
        }
    }

    public function test_web_uses_same_records_and_legacy_route_cannot_overwrite(): void
    {
        $this->withoutMiddleware([CheckDeviceBinding::class, CheckDynamicBarrier::class]);
        $driver = $this->person();
        $instructor = $this->person('instructor');
        $task = $this->task();
        $this->actingAs($driver)->get('/formular')->assertOk()->assertSee('Отправить на проверку')->assertHeader('Cache-Control', 'no-store, private');
        $this->post('/rosisi/log-formular/'.$task->id, $this->body())->assertRedirect(route('formular.index'));
        $log = UserFormularLog::firstOrFail();
        $this->actingAs($instructor)->get('/formular?review=1')->assertOk()->assertSee('Подтвердить')->assertSee($driver->name);
        $this->post('/formular/logs/'.$log->id.'/review', ['operation_id' => (string) Str::uuid(), 'version' => 1, 'decision' => 'confirmed'])->assertRedirect();
        $this->actingAs($driver)->post('/rosisi/log-formular/'.$task->id, $this->body(2))->assertConflict();
        $this->get('/formular')->assertOk()->assertSee('Подтверждено')->assertDontSee('Отправить на проверку');
        $this->get('/formular?review=1')->assertForbidden();
    }

    public function test_statistics_web_does_not_leak_other_column_formular(): void
    {
        $this->withoutMiddleware([CheckDeviceBinding::class, CheckDynamicBarrier::class]);
        $driver = $this->person('driver', '16');
        $task = $this->task();
        UserFormularLog::create(['user_id' => $driver->id, 'formular_task_id' => $task->id, 'completed_at' => now(), 'entry_text' => 'PRIVATE_OTHER_COLUMN']);
        $this->actingAs($this->person('instructor'))->get('/rosisi/statistics')->assertOk()->assertDontSee('PRIVATE_OTHER_COLUMN');
    }

    public function test_super_admin_without_profile_can_submit_and_review_all_columns_but_not_self(): void
    {
        $super = User::factory()->create(['role' => 'super_admin']);
        $task = $this->task();
        $driver = $this->person('driver', '15');
        $other = $this->person('driver', '16');
        $instructor = User::factory()->create(['role' => 'instructor']);
        $id = $this->api($other)->postJson('/api/mobile/formular/tasks/'.$task->id, $this->body())->assertOk()->json('log.id');
        $own = $this->api($super)->postJson('/api/mobile/formular/tasks/'.$task->id, $this->body())->assertOk()->json('log.id');
        $this->getJson('/api/mobile/formular/review')->assertOk()->assertJsonPath('column', 'Все колонны')->assertJsonPath('stats.total', 4)->assertJsonPath('stats.pending', 2);
        $this->postJson('/api/mobile/formular/logs/'.$id.'/review', ['operation_id' => (string) Str::uuid(), 'version' => 1, 'decision' => 'confirmed'])->assertOk()->assertJsonPath('log.reviewed_by', $super->id);
        $this->postJson('/api/mobile/formular/logs/'.$own.'/review', ['operation_id' => (string) Str::uuid(), 'version' => 1, 'decision' => 'confirmed'])->assertForbidden();
        $this->api($driver)->getJson('/api/mobile/formular/review')->assertForbidden();
        $this->api($this->person('instructor', '15'))->getJson('/api/mobile/formular/review')->assertOk()->assertJsonPath('stats.total', 1);
        $this->withoutMiddleware([CheckDeviceBinding::class, CheckDynamicBarrier::class]);
        $this->actingAs($super, 'web')->get('/formular?review=1')->assertOk()->assertSee('Все колонны');
        $this->actingAs($super, 'web')->get('/formular')->assertOk()->assertSee('Мои записи');
    }

    public function test_super_admin_is_rechecked_after_deactivation_and_monitor_token_stays_blocked(): void
    {
        $super = User::factory()->create(['role' => 'super_admin']);
        $this->api($super)->getJson('/api/mobile/formular/review')->assertOk();
        $super->update(['role' => 'admin']);
        $this->getJson('/api/mobile/formular/review')->assertForbidden();
        $super->update(['role' => 'super_admin', 'is_active' => false]);
        $this->getJson('/api/mobile/formular')->assertForbidden();
        $super->update(['is_active' => true]);
        $this->app['auth']->forgetGuards();
        $this->withToken($super->createToken('monitor', ['monitor:read'])->plainTextToken)->getJson('/api/mobile/formular/review')->assertForbidden();
    }
}
