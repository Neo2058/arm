<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckDeviceBinding;
use App\Http\Middleware\CheckDynamicBarrier;
use App\Models\JournalCrewNormative;
use App\Models\JournalDocument;
use App\Models\JournalTask;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\ClickHouseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MobileJournalTest extends TestCase
{
    use RefreshDatabase;

    private function api(User $user, string $path)
    {
        $this->app['auth']->forgetGuards();
        $token = $user->createToken('journal-test')->plainTextToken;

        return $this->withToken($token)->getJson('/api/mobile/journal'.$path);
    }

    private function person(string $role, ?string $column = '15'): User
    {
        $user = User::factory()->create(['role' => $role]);
        if ($column !== null) {
            UserProfile::create(['user_id' => $user->id, 'column' => $column]);
        }

        return $user;
    }

    private function task(User $user, string $column, string $title, string $status = 'pending'): JournalTask
    {
        return JournalTask::create(['user_id' => $user->id, 'column' => $column, 'title' => $title, 'status' => $status, 'source' => 'manual', 'due_date' => today()->subDay()]);
    }

    public function test_all_read_endpoints_require_token_and_current_instructor_role(): void
    {
        foreach (['', '/tasks', '/crew'] as $path) {
            $this->app['auth']->forgetGuards();
            $this->withHeader('Authorization', '')->getJson('/api/mobile/journal'.$path)->assertUnauthorized();
            foreach (['driver', 'student', 'dispatcher', 'admin', 'super_admin', 'tormoznik', 'nachalnik'] as $role) {
                $this->api($this->person($role), $path)->assertForbidden();
            }
        }
        $instructor = $this->person('instructor');
        $token = $instructor->createToken('old')->plainTextToken;
        User::whereKey($instructor->id)->update(['role' => 'driver']);
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/mobile/journal')->assertForbidden();
    }

    public function test_no_column_does_not_expose_unassigned_tasks_or_crew(): void
    {
        $user = $this->person('instructor', null);
        $this->person('driver', null);
        JournalTask::create(['user_id' => $user->id, 'column' => null, 'title' => 'Hidden', 'status' => 'pending']);
        $this->api($user, '')->assertOk()->assertJsonPath('column', null)->assertJsonPath('stats.tasks_pending', 0);
        $this->api($user, '/tasks')->assertOk()->assertJsonCount(0, 'tasks');
        $this->api($user, '/crew')->assertOk()->assertJsonCount(0, 'crew');
    }

    public function test_tasks_are_personal_current_column_filtered_paginated_and_private(): void
    {
        $user = $this->person('instructor');
        $other = $this->person('instructor');
        $this->task($user, '15', 'Own pending');
        $this->task($user, '15', 'Own done', 'done');
        $this->task($other, '15', 'Another instructor');
        $this->task($user, '16', 'Old column');
        $this->api($user, '/tasks?status=pending&per_page=1')->assertOk()->assertJsonCount(1, 'tasks')
            ->assertJsonPath('tasks.0.title', 'Own pending')->assertJsonPath('tasks.0.is_overdue', true)
            ->assertJsonPath('pagination.total', 1)->assertDontSee('Another instructor')->assertDontSee('Old column');
        $response = $this->api($user, '/tasks?per_page=1');
        $response->assertOk()->assertJsonPath('pagination.next_page', 2);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->api($user, '/tasks?status=accepted')->assertUnprocessable();
        $this->api($user, '/tasks?per_page=1000')->assertUnprocessable();
        $this->api($user, '/crew?page=0')->assertUnprocessable();
        UserProfile::where('user_id', $user->id)->update(['column' => '16']);
        $this->api($user, '/tasks')->assertOk()->assertJsonCount(1, 'tasks')->assertJsonPath('tasks.0.title', 'Old column');
    }

    public function test_crew_only_contains_column_drivers_and_own_normatives_without_writing_on_get(): void
    {
        $user = $this->person('instructor');
        $other = $this->person('instructor');
        $driver = $this->person('driver');
        $foreign = $this->person('driver', '16');
        $this->person('student');
        $this->person('driver'); // no normative records yet
        JournalCrewNormative::create(['user_id' => $driver->id, 'instructor_id' => $user->id, 'column' => '15', 'type' => 'kip_linia', 'last_date' => today()->subMonths(5), 'next_date' => today()->subDay()]);
        JournalCrewNormative::create(['user_id' => $driver->id, 'instructor_id' => $other->id, 'column' => '15', 'type' => 'kip_kru', 'last_date' => '2026-01-01']);
        JournalCrewNormative::create(['user_id' => $foreign->id, 'instructor_id' => $user->id, 'column' => '16', 'type' => 'atz']);
        $response = $this->api($user, '/crew');
        $response->assertOk()->assertJsonCount(2, 'crew')->assertDontSee($foreign->name)->assertDontSee($foreign->email);
        $record = collect($response->json('crew'))->firstWhere('id', (string) $driver->id);
        $this->assertCount(9, $record['normatives']);
        $this->assertTrue($record['normatives'][0]['recorded']);
        $this->assertTrue($record['normatives'][0]['is_overdue']);
        $this->assertFalse($record['normatives'][3]['recorded']);
        $this->assertNull($record['normatives'][3]['last_date']);
        $this->assertDatabaseCount('journal_crew_normatives', 3);
        $this->assertDatabaseCount('journal_normative_settings', 0);
        $this->api($user, '/crew?per_page=1')->assertOk()->assertJsonCount(1, 'crew')->assertJsonPath('pagination.next_page', 2);
    }

    public function test_summary_matches_existing_web_journal_and_real_counts(): void
    {
        ClickHouseService::fake();
        $user = $this->person('instructor');
        $driver = $this->person('driver');
        $this->task($user, '15', 'Pending');
        $done = $this->task($user, '15', 'Report', 'done');
        $done->update(['source' => 'report']);
        $this->task($user, '16', 'Old column');
        JournalDocument::create(['user_id' => $user->id, 'column' => '15', 'title' => 'Secret path', 'file_path' => 'private/secret.pdf']);
        foreach (['kip_linia', 'kip_kru'] as $type) {
            JournalCrewNormative::create(['user_id' => $driver->id, 'instructor_id' => $user->id, 'column' => '15', 'type' => $type, 'last_date' => $type === 'kip_linia' ? today() : null]);
        }
        $api = $this->api($user, '');
        $api->assertOk()->assertJsonPath('stats.column_members', 2)->assertJsonPath('stats.tasks_pending', 1)
            ->assertJsonPath('stats.tasks_done', 1)->assertJsonPath('stats.reports_written', 1)
            ->assertJsonPath('stats.documents_count', 1)->assertJsonPath('stats.norms_completed', 50)->assertDontSee('secret.pdf');
        $this->withoutMiddleware([CheckDeviceBinding::class, CheckDynamicBarrier::class]);
        $this->actingAs($user, 'web')->withHeader('Authorization', '')->get('/journal')->assertOk()->assertViewHas('stats', $api->json('stats'));
    }

    public function test_deactivated_user_and_revoked_token_cannot_read_journal(): void
    {
        $user = $this->person('instructor');
        $token = $user->createToken('old')->plainTextToken;
        User::whereKey($user->id)->update(['is_active' => false]);
        $this->withToken($token)->getJson('/api/mobile/journal/crew')->assertForbidden();
        $user->tokens()->delete();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/mobile/journal')->assertUnauthorized();
    }
}
