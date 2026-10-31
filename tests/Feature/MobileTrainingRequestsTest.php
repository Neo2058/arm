<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MobileTrainingRequestsTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'instructor', string $column = '15'): User
    {
        $u = User::factory()->create(['role' => $role]);
        UserProfile::create(['user_id' => $u->id, 'column' => $column]);

        return $u;
    }

    private function api(User $u, string $method, string $path, array $body = [])
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($u->createToken('test')->plainTextToken)->json($method, '/api/mobile/journal/training-requests'.$path, $body);
    }

    private function create(User $u, User $driver): array
    {
        return $this->api($u, 'POST', '', ['operation_id' => (string) Str::uuid(), 'planning_month' => '2026-10', 'shift_type' => 'А2', 'participant_count' => 1, 'participants' => [$driver->id]])->assertOk()->json();
    }

    public function test_token_crud_accounting_and_no_store(): void
    {
        $u = $this->user();
        $d = $this->user('driver');
        $card = $this->create($u, $d);
        $this->api($u, 'GET', '?month=2026-10')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('cards.0.id', $card['id'])->assertJsonPath('cards.0.participants.0.id', (string) $d->id)->assertJsonPath('used', 1);
        $this->api($u, 'POST', '/periods', ['operation_id' => (string) Str::uuid(), 'atz_months' => 2, 'atz_line_months' => 3])->assertOk();
        $data = ['operation_id' => (string) Str::uuid(), 'version' => 1, 'normative_type' => 'atz', 'performed_on' => '2026-01-31', 'attendees' => [$d->id]];
        $first = $this->api($u, 'POST', '/'.$card['id'].'/complete', $data)->assertOk()->json();
        $this->api($u, 'POST', '/'.$card['id'].'/complete', $data)->assertOk()->assertExactJson($first);
        $this->api($u, 'GET', '?month=2026-10')->assertOk()->assertJsonPath('cards.0.completion.attendees.0.name', $d->name)->assertJsonPath('periods.atz_months', 2);
    }

    public function test_scope_membership_and_inactive_tokens(): void
    {
        $u = $this->user();
        $other = $this->user();
        $d = $this->user('driver');
        $outsider = $this->user('driver', '16');
        $card = $this->create($u, $d);
        $this->api($other, 'GET', '?month=2026-10')->assertOk()->assertJsonCount(0, 'cards');
        $this->api($u, 'GET', '/crew')->assertOk()->assertJsonCount(1, 'crew')->assertJsonPath('crew.0.id', (string) $d->id);
        $this->api($other, 'DELETE', '/'.$card['id'], ['operation_id' => (string) Str::uuid(), 'version' => 1])->assertNotFound();
        $this->api($d, 'GET', '?month=2026-10')->assertForbidden();
        $this->api($u, 'GET', '?month=2026-13')->assertUnprocessable();
        UserProfile::where('user_id', $u->id)->update(['column' => '16']);
        $this->api($u, 'GET', '?month=2026-10')->assertOk()->assertJsonCount(0, 'cards');
        $u->update(['is_active' => false]);
        $this->api($u, 'GET', '?month=2026-10')->assertForbidden();
    }

    public function test_update_delete_and_month_limit_shared_service(): void
    {
        $u = $this->user();
        $d = $this->user('driver');
        $card = $this->create($u, $d);
        $body = ['operation_id' => (string) Str::uuid(), 'version' => 1, 'planning_month' => '2026-10', 'shift_type' => 'А3', 'participant_count' => 1, 'participants' => [$d->id]];
        $this->api($u, 'PATCH', '/'.$card['id'], $body)->assertOk()->assertJsonPath('version', '2');
        $this->api($u, 'DELETE', '/'.$card['id'], ['operation_id' => (string) Str::uuid(), 'version' => 2])->assertOk();
        for ($i = 0; $i < 10; $i++) {
            $this->create($u, $d);
        }
        $this->api($u, 'POST', '', array_replace(array_diff_key($body, ['version' => true]), ['operation_id' => (string) Str::uuid()]))->assertUnprocessable();
        $this->assertDatabaseCount('instructor_training_requests', 10);
    }
}
