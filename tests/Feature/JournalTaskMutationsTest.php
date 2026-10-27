<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckDeviceBinding;
use App\Http\Middleware\CheckDynamicBarrier;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\ClickHouseService;
use App\Services\Journal\JournalTasks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class JournalTaskMutationsTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'instructor', string $column = '15'): User
    {
        $user = User::factory()->create(['role' => $role]);
        UserProfile::create(['user_id' => $user->id, 'column' => $column]);

        return $user;
    }

    private function api(User $user, string $method, string $path, array $data = [])
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($user->createToken('test')->plainTextToken)->json($method, '/api/mobile/journal/tasks'.$path, $data);
    }

    private function create(User $user): array
    {
        return $this->api($user, 'POST', '', ['operation_id' => (string) Str::uuid(), 'title' => 'Задача', 'description' => 'Описание', 'due_date' => '2026-12-01'])->assertOk()->json('task');
    }

    public function test_create_replay_and_changed_content_are_atomic(): void
    {
        ClickHouseService::fake();
        $u = $this->user();
        $data = ['operation_id' => (string) Str::uuid(), 'title' => 'Задача'];
        $first = $this->api($u, 'POST', '', $data)->assertOk()->json();
        $this->api($u, 'POST', '', $data)->assertOk()->assertExactJson($first);
        $this->api($u, 'POST', '', array_merge($data, ['title' => 'Другая']))->assertConflict();
        $this->assertDatabaseCount('journal_tasks', 1);
        $this->assertDatabaseCount('journal_task_operations', 1);
    }

    public function test_update_complete_delete_replays_and_stale_version(): void
    {
        ClickHouseService::fake();
        $u = $this->user();
        $task = $this->create($u);
        $id = $task['id'];
        $edit = ['operation_id' => (string) Str::uuid(), 'version' => '1', 'title' => 'Новое', 'description' => null, 'due_date' => null];
        $this->api($u, 'PATCH', '/'.$id, $edit)->assertOk()->assertJsonPath('task.version', '2')->assertJsonPath('task.due_date', null);
        $this->api($u, 'PATCH', '/'.$id, $edit)->assertOk()->assertJsonPath('task.version', '2');
        $this->api($u, 'PATCH', '/'.$id, array_merge($edit, ['operation_id' => (string) Str::uuid()]))->assertStatus(412)->assertJsonPath('current.version', '2');
        $done = ['operation_id' => (string) Str::uuid(), 'version' => '2'];
        $this->api($u, 'POST', '/'.$id.'/complete', $done)->assertOk()->assertJsonPath('task.status', 'done')->assertJsonPath('task.version', '3');
        $this->api($u, 'POST', '/'.$id.'/complete', $done)->assertOk()->assertJsonPath('task.version', '3');
        $remove = ['operation_id' => (string) Str::uuid(), 'version' => '3'];
        $this->api($u, 'DELETE', '/'.$id, $remove)->assertOk()->assertJsonPath('deleted', true);
        $this->api($u, 'DELETE', '/'.$id, $remove)->assertOk()->assertJsonPath('deleted', true);
        $this->assertDatabaseCount('journal_tasks', 0);
        $this->assertDatabaseCount('journal_task_operations', 4);
    }

    public function test_owner_column_role_and_validation_are_enforced(): void
    {
        ClickHouseService::fake();
        $u = $this->user();
        $other = $this->user();
        $t = $this->create($u);
        foreach (['PATCH', 'DELETE'] as $method) {
            $this->api($other, $method, '/'.$t['id'], ['operation_id' => (string) Str::uuid(), 'version' => '1', 'title' => 'X'])->assertNotFound();
        }
        $this->api($other, 'POST', '/'.$t['id'].'/complete', ['operation_id' => (string) Str::uuid(), 'version' => '1'])->assertNotFound();
        $this->api($this->user('driver'), 'POST', '', ['operation_id' => (string) Str::uuid(), 'title' => 'X'])->assertForbidden();
        $this->api($u, 'POST', '', ['title' => 'No key'])->assertUnprocessable();
        $this->api($u, 'PATCH', '/'.$t['id'], ['operation_id' => (string) Str::uuid(), 'title' => 'No version'])->assertUnprocessable();
        $this->api($u, 'POST', '', ['operation_id' => (string) Str::uuid(), 'title' => 'X', 'due_date' => '2026-02-30'])->assertUnprocessable();
        $this->api($u, 'POST', '', ['operation_id' => (string) Str::uuid(), 'title' => 'X', 'due_date' => '0000-01-01'])->assertUnprocessable();
        UserProfile::where('user_id', $u->id)->update(['column' => '16']);
        $this->api($u, 'DELETE', '/'.$t['id'], ['operation_id' => (string) Str::uuid(), 'version' => '1'])->assertNotFound();
        $this->assertDatabaseCount('journal_tasks', 1);
    }

    public function test_web_and_mobile_share_version_and_records(): void
    {
        ClickHouseService::fake();
        $u = $this->user();
        $t = $this->create($u);
        $this->withoutMiddleware([CheckDeviceBinding::class, CheckDynamicBarrier::class]);
        $this->actingAs($u, 'web')->withHeader('Authorization', '')->post('/journal/todo/'.$t['id'].'/update', ['title' => 'Правка из веба'])->assertRedirect();
        $this->assertDatabaseHas('journal_tasks', ['id' => $t['id'], 'version' => 2, 'title' => 'Правка из веба']);
        $this->api($u, 'POST', '/'.$t['id'].'/complete', ['operation_id' => (string) Str::uuid(), 'version' => '1'])->assertStatus(412);
    }

    public function test_receipts_require_current_role_and_same_column(): void
    {
        ClickHouseService::fake();
        $u = $this->user();
        $data = ['operation_id' => (string) Str::uuid(), 'title' => 'X'];
        $this->api($u, 'POST', '', $data)->assertOk();
        UserProfile::where('user_id', $u->id)->update(['column' => '16']);
        $this->api($u, 'POST', '', $data)->assertNotFound();
        User::whereKey($u->id)->update(['role' => 'driver']);
        $this->api($u, 'POST', '', $data)->assertForbidden();
    }

    public function test_omission_and_explicit_null_are_distinct_updates(): void
    {
        ClickHouseService::fake();
        $u = $this->user();
        $t = $this->create($u);
        $edit = ['operation_id' => (string) Str::uuid(), 'version' => '1', 'title' => 'X'];
        $this->api($u, 'PATCH', '/'.$t['id'], $edit)->assertOk()->assertJsonPath('task.description', 'Описание');
        $this->api($u, 'PATCH', '/'.$t['id'], array_merge($edit, ['description' => null]))->assertConflict();
    }

    public function test_case_insensitive_uuid_and_rollback(): void
    {
        ClickHouseService::fake();
        $u = $this->user();
        $id = (string) Str::uuid();
        $this->api($u, 'POST', '', ['operation_id' => strtoupper($id), 'title' => 'X'])->assertOk();
        $this->api($u, 'POST', '', ['operation_id' => $id, 'title' => 'X'])->assertOk();
        $this->assertDatabaseCount('journal_tasks', 1);
        try {
            DB::transaction(function () use ($u) {
                app(JournalTasks::class)->mutate($u, 'create', null, ['operation_id' => (string) Str::uuid(), 'title' => 'Rollback']);
                throw new \RuntimeException('Rollback test');
            });
        } catch (\RuntimeException $e) {
            $this->assertSame('Rollback test', $e->getMessage());
        }
        $this->assertDatabaseCount('journal_tasks', 1);
        $this->assertDatabaseCount('journal_task_operations', 1);
    }

    public function test_rollback_refuses_to_discard_receipts(): void
    {
        ClickHouseService::fake();
        $u = $this->user();
        $this->create($u);
        $migration = require database_path('migrations/2026_10_27_150000_version_journal_tasks.php');
        try {
            $migration->down();
            $this->fail('Rollback must refuse committed operations');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('must not be discarded', $e->getMessage());
        }
        $this->assertDatabaseCount('journal_tasks', 1);
        $this->assertDatabaseCount('journal_task_operations', 1);
    }

    public function test_web_form_replay_and_stale_edit_are_safe(): void
    {
        ClickHouseService::fake();
        $u = $this->user();
        $task = $this->create($u);
        $this->withoutMiddleware([CheckDeviceBinding::class, CheckDynamicBarrier::class]);
        $page = $this->actingAs($u, 'web')->withHeader('Authorization', '')->get('/journal')->assertOk();
        preg_match('/name="operation_id" value="([^"]+)"/', $page->getContent(), $match);
        $this->assertNotEmpty($match[1]);
        $input = ['operation_id' => $match[1], 'title' => 'Web creation'];
        $this->post('/journal/todo', $input)->assertRedirect(route('journal.index'));
        $this->post('/journal/todo', $input)->assertRedirect(route('journal.index'));
        $this->assertDatabaseCount('journal_tasks', 2);
        app(JournalTasks::class)->mutate($u, 'update', (int) $task['id'], ['operation_id' => (string) Str::uuid(), 'version' => '1', 'title' => 'Mobile changed']);
        $this->post('/journal/todo/'.$task['id'].'/update', ['operation_id' => (string) Str::uuid(), 'version' => '1', 'title' => 'Stale web'])->assertRedirect(route('journal.index'))->assertSessionHas('error');
        $this->assertDatabaseHas('journal_tasks', ['id' => $task['id'], 'title' => 'Mobile changed', 'version' => 2]);
    }
}
