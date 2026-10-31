<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckDeviceBinding;
use App\Http\Middleware\CheckDynamicBarrier;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class InstructorTrainingRequestsTest extends TestCase
{
    use RefreshDatabase;

    private function person(string $role = 'instructor', string $column = '15'): User
    {
        $u = User::factory()->create(['role' => $role]);
        UserProfile::create(['user_id' => $u->id, 'column' => $column]);

        return $u;
    }

    private function body(array $members, string $month = '2026-11'): array
    {
        return ['operation_id' => (string) Str::uuid(), 'planning_month' => $month, 'shift_type' => 'А2', 'participant_count' => count($members), 'participants' => array_map(fn ($u) => $u->id, $members)];
    }

    private function send(User $u, string $method, array $body, string $suffix = '')
    {
        $this->withoutMiddleware([CheckDeviceBinding::class, CheckDynamicBarrier::class]);

        return $this->actingAs($u)->json($method, '/journal/training-requests'.$suffix, $body);
    }

    public function test_save_replay_and_page(): void
    {
        $u = $this->person();
        $a = $this->person('driver');
        $b = $this->person('driver');
        $body = $this->body([$a, $b]);
        $first = $this->send($u, 'POST', $body)->assertOk()->json();
        $this->send($u, 'POST', $body)->assertOk()->assertExactJson($first);
        $this->send($u, 'POST', array_replace($body, ['operation_id' => strtoupper($body['operation_id'])]))->assertOk()->assertExactJson($first);
        $this->send($u, 'POST', array_replace($body, ['shift_type' => 'А3']))->assertConflict();
        $this->assertDatabaseCount('instructor_training_requests', 1);
        $this->assertDatabaseCount('instructor_training_members', 2);
        $this->actingAs($u)->get('/journal/training-requests?month=2026-11')->assertOk()->assertSee('Подстройки АТЗ')->assertSee($a->name)->assertSee('А2');
        $this->assertDatabaseCount('journal_crew_normatives', 0);
    }

    public function test_count_own_column_activity_and_distinct(): void
    {
        $u = $this->person();
        $a = $this->person('driver');
        $foreign = $this->person('driver', '16');
        $this->send($u, 'POST', $this->body([$foreign]))->assertUnprocessable();
        $this->send($u, 'POST', array_replace($this->body([$a]), ['participant_count' => 4]))->assertUnprocessable();
        $this->send($u, 'POST', $this->body([$a, $a]))->assertUnprocessable();
        $this->send($u, 'POST', $this->body([$u]))->assertUnprocessable();
        $a->update(['is_active' => false]);
        $this->send($u, 'POST', $this->body([$a]))->assertUnprocessable();
        $this->assertDatabaseCount('instructor_training_requests', 0);
        $this->assertDatabaseCount('instructor_training_operations', 0);
    }

    public function test_limit_per_instructor_per_month_and_move(): void
    {
        $u = $this->person();
        $a = $this->person('driver');
        for ($i = 0; $i < 10; $i++) {
            $this->send($u, 'POST', $this->body([$a]))->assertOk();
        }
        $this->send($u, 'POST', $this->body([$a]))->assertUnprocessable();
        $next = $this->send($u, 'POST', $this->body([$a], '2026-12'))->assertOk()->json();
        $body = $this->body([$a]) + ['version' => 1];
        $this->send($u, 'PATCH', $body, '/'.$next['id'])->assertUnprocessable();
        $this->send($this->person(), 'POST', $this->body([$a]))->assertOk();
        $this->assertDatabaseCount('instructor_training_requests', 12);
    }

    public function test_update_delete_owner_version_and_replay(): void
    {
        $u = $this->person();
        $other = $this->person();
        $a = $this->person('driver');
        $b = $this->person('driver');
        $row = $this->send($u, 'POST', $this->body([$a]))->assertOk()->json();
        $update = $this->body([$b]) + ['version' => 1];
        $this->send($other, 'PATCH', $update, '/'.$row['id'])->assertNotFound();
        $this->send($u, 'PATCH', $update, '/'.$row['id'])->assertOk()->assertJsonPath('version', '2');
        $this->send($u, 'PATCH', $update, '/'.$row['id'])->assertOk()->assertJsonPath('version', '2');
        $this->assertDatabaseMissing('instructor_training_members', ['request_id' => $row['id'], 'user_id' => $a->id]);
        $this->send($u, 'PATCH', array_replace($update, ['operation_id' => (string) Str::uuid()]), '/'.$row['id'])->assertUnprocessable();
        $delete = ['operation_id' => (string) Str::uuid(), 'version' => 2];
        $this->send($other, 'DELETE', $delete, '/'.$row['id'])->assertNotFound();
        $this->send($u, 'DELETE', $delete, '/'.$row['id'])->assertOk();
        $this->send($u, 'DELETE', $delete, '/'.$row['id'])->assertOk();
        $this->assertDatabaseCount('instructor_training_members', 0);
    }

    public function test_recheck_column_role_and_no_leak(): void
    {
        $u = $this->person();
        $a = $this->person('driver');
        $body = $this->body([$a]);
        $row = $this->send($u, 'POST', $body)->assertOk()->json();
        UserProfile::where('user_id', $u->id)->update(['column' => '16']);
        $this->send($u, 'POST', $body)->assertNotFound();
        $this->send($u, 'DELETE', ['operation_id' => (string) Str::uuid(), 'version' => 1], '/'.$row['id'])->assertNotFound();
        $this->actingAs($u)->get('/journal/training-requests?month=2026-11')->assertOk()->assertDontSee($a->name);
        $this->send($this->person('driver'), 'POST', $this->body([$a]))->assertForbidden();
        $u->update(['is_active' => false]);
        $this->send($u, 'POST', $body)->assertForbidden();
    }

    public function test_transaction_rolls_back_and_requires_valid_fields(): void
    {
        $u = $this->person();
        $a = $this->person('driver');
        $this->send($u, 'POST', array_replace($this->body([$a]), ['planning_month' => '2026-13']))->assertUnprocessable();
        $this->send($u,'POST',array_replace($this->body([$a]),['shift_type' => '   ']))->assertUnprocessable();
        DB::statement("CREATE TRIGGER reject_training_member BEFORE INSERT ON instructor_training_members BEGIN SELECT RAISE(ABORT,'forced failure'); END");
        $this->send($u,'POST',$this->body([$a]))->assertStatus(500);
        $this->assertDatabaseCount('instructor_training_requests',0);
        $this->assertDatabaseCount('instructor_training_operations',0);
    }
}
