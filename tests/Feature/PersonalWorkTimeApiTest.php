<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WorkShift;
use App\Models\WorkTimeMutation;
use App\Services\ClickHouseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

class PersonalWorkTimeApiTest extends TestCase
{
    use RefreshDatabase;

    private string $api = '/api/mobile/worktime';

    protected function setUp(): void
    {
        parent::setUp();
        ClickHouseService::fake();
        Cache::flush();
    }

    private function asUser(User $user): void
    {
        Auth::forgetGuards();
        $this->withToken($user->createToken('worktime-test')->plainTextToken);
    }

    private function input(string $start = '09:00', string $end = '17:00'): array
    {
        return ['shift_date' => '2026-10-27', 'started_at' => '2026-10-27 '.$start, 'ended_at' => '2026-10-27 '.$end, 'break_duration' => 60];
    }

    private function createShift(): array
    {
        return $this->postJson($this->api.'/shifts', $this->input() + ['operation_id' => (string) Str::uuid()])->assertCreated()->json('shift');
    }

    public function test_guest_and_inactive_accounts_have_no_access(): void
    {
        foreach ([$this->api.'/month?month=2026-10', $this->api.'/settings?month=2026-10', '/api/worktime/v2/month?month=2026-10'] as $url) {
            $this->getJson($url)->assertUnauthorized();
        }
        $this->postJson($this->api.'/shifts', [])->assertUnauthorized();
        $user = User::factory()->create(['is_active' => false]);
        $this->asUser($user);
        $this->getJson($this->api.'/month?month=2026-10')->assertForbidden();
        $this->patchJson($this->api.'/settings/2026-10', [])->assertForbidden();
    }

    public function test_shared_web_and_mobile_contract_and_read_only_gets(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $this->actingAs($user);
        $before = ClickHouseService::recorded();
        $this->getJson('/api/worktime/v2/settings?month=2026-10')->assertOk()->assertJsonPath('revision', 0)->assertJsonPath('settings.line_rate_kopecks', 57348)->assertHeader('Cache-Control', 'no-store, private');
        $this->assertDatabaseCount('work_time_month_settings', 0);
        $this->assertSame($before, ClickHouseService::recorded());
        $id = $this->postJson('/api/worktime/v2/shifts', $this->input() + ['operation_id' => (string) Str::uuid()])->assertCreated()->json('shift.id');
        $this->getJson($this->api.'/shifts/'.$id)->assertOk()->assertJsonPath('shift.revision', 1);
        $this->getJson($this->api.'/month?month=2026-10')->assertOk()->assertJsonPath('totals.paid_minutes', 480);
    }

    public function test_web_device_and_barrier_checks_are_kept(): void
    {
        $user = User::factory()->driver()->create();
        $this->actingAs($user)->getJson('/api/worktime/v2/settings?month=2026-10')->assertRedirect(route('device.register.form'));
        $user->devices()->create(['device_key' => 'worktime-device', 'is_approved' => true]);
        $this->withHeader('X-Device-Key', 'worktime-device')->getJson('/api/worktime/v2/settings?month=2026-10')->assertRedirect(route('barrier.show'));
        $this->withSession(['dynamic_barrier_passed' => true])->getJson('/api/worktime/v2/settings?month=2026-10')->assertOk();
    }

    public function test_mutation_replay_keeps_original_response_even_after_later_edits(): void
    {
        $user = User::factory()->create();
        $this->asUser($user);
        $create = $this->input() + ['operation_id' => (string) Str::uuid()];
        $original = $this->postJson($this->api.'/shifts', $create)->assertCreated()->json();
        $id = $original['shift']['id'];
        $edit = $this->input('10:00', '18:00') + ['operation_id' => (string) Str::uuid(), 'expected_revision' => 1];
        $edited = $this->putJson($this->api.'/shifts/'.$id, $edit)->assertOk()->assertJsonPath('shift.revision', 2)->json();
        $this->assertSame($edited, $this->putJson($this->api.'/shifts/'.$id, $edit)->assertOk()->json());
        $this->assertSame($original, $this->postJson($this->api.'/shifts', $create)->assertCreated()->json());
        $this->assertSame(2, WorkShift::findOrFail($id)->revision);
        $this->assertDatabaseCount('work_shifts', 1);
        $this->assertDatabaseCount('work_time_mutations', 2);
    }

    public function test_stale_revision_and_changed_uuid_payload_do_not_overwrite(): void
    {
        $user = User::factory()->create();
        $this->asUser($user);
        $shift = $this->createShift();
        $edit = $this->input('10:00', '18:00') + ['operation_id' => (string) Str::uuid(), 'expected_revision' => 1];
        $this->putJson($this->api.'/shifts/'.$shift['id'], $edit)->assertOk();
        $this->putJson($this->api.'/shifts/'.$shift['id'], array_replace($edit, ['operation_id' => (string) Str::uuid()]))->assertConflict();
        $this->putJson($this->api.'/shifts/'.$shift['id'], array_replace($edit, ['ended_at' => '2026-10-27 19:00']))->assertConflict();
        $this->assertSame(2, WorkShift::find($shift['id'])->revision);
    }

    public function test_foreign_shift_cannot_be_read_previewed_or_updated(): void
    {
        $owner = User::factory()->create();
        $this->asUser($owner);
        $shift = $this->createShift();
        $this->asUser(User::factory()->create());
        $this->getJson($this->api.'/shifts/'.$shift['id'])->assertNotFound();
        $edit = $this->input() + ['expected_revision' => 1, 'operation_id' => (string) Str::uuid()];
        $this->postJson($this->api.'/shifts/'.$shift['id'].'/preview', $edit)->assertNotFound();
        $this->putJson($this->api.'/shifts/'.$shift['id'], $edit)->assertNotFound();
        $this->getJson($this->api.'/month?month=2026-10&user_id='.$owner->id)->assertJsonCount(0, 'shifts');
    }

    public function test_settings_have_revision_history_and_refresh_is_explicit(): void
    {
        $user = User::factory()->create();
        $this->asUser($user);
        $shift = $this->createShift();
        $settings = ['operation_id' => (string) Str::uuid(), 'expected_revision' => 0, 'settings' => ['line_rate_kopecks' => 60000, 'rest_dates' => ['2026-10-26']]];
        $response = $this->patchJson($this->api.'/settings/2026-10', $settings)->assertOk()->assertJsonPath('revision', 1)->json();
        $this->assertSame($response, $this->patchJson($this->api.'/settings/2026-10', $settings)->assertOk()->json());
        $this->getJson($this->api.'/settings?month=2026-11')->assertOk()->assertJsonPath('revision', 0)
            ->assertJsonPath('settings.line_rate_kopecks', 60000)->assertJsonPath('settings.rest_dates', []);
        $this->getJson($this->api.'/settings?month=2026-09')->assertOk()->assertJsonPath('settings.line_rate_kopecks', 57348);
        $this->getJson($this->api.'/shifts/'.$shift['id'])->assertJsonPath('shift.calculation_snapshot.line_rate_kopecks', 57348);
        $edit = $this->input() + ['operation_id' => (string) Str::uuid(), 'expected_revision' => 1];
        $this->postJson($this->api.'/shifts/'.$shift['id'].'/preview', $edit)->assertOk()->assertJsonPath('shift.calculation_snapshot.line_rate_kopecks', 57348);
        $this->putJson($this->api.'/shifts/'.$shift['id'], $edit)->assertOk()->assertJsonPath('shift.calculation_snapshot.line_rate_kopecks', 57348);
        $edit = array_replace($edit, ['operation_id' => (string) Str::uuid(), 'expected_revision' => 2, 'refresh_snapshot' => true]);
        $preview = $this->postJson($this->api.'/shifts/'.$shift['id'].'/preview', $edit)->assertOk()->assertJsonPath('shift.calculation_snapshot.line_rate_kopecks', 60000)->json('shift.calculation_result');
        $saved = $this->putJson($this->api.'/shifts/'.$shift['id'], $edit)->assertOk()->json('shift.calculation_result');
        $this->assertSame($preview, $saved);
        $this->patchJson($this->api.'/settings/2026-10', array_replace($settings, ['operation_id' => (string) Str::uuid()]))->assertConflict();
    }

    public function test_settings_validation_audit_and_account_isolation(): void
    {
        $user = User::factory()->create();
        $this->asUser($user);
        $body = ['operation_id' => (string) Str::uuid(), 'expected_revision' => 0, 'settings' => ['line_rate_kopecks' => 61234]];
        $this->patchJson($this->api.'/settings/2026-10', $body + ['user_id' => 99999])->assertOk();
        $events = array_values(array_filter(ClickHouseService::recorded(), fn ($r) => $r['action_type'] === 'work_time.settings_saved'));
        $this->assertCount(1, $events);
        $details = $events[0]['details'];
        if (is_string($details)) {
            $details = json_decode($details, true);
        }
        $this->assertSame(['user_id' => $user->id, 'month' => '2026-10', 'changed_fields' => ['line_rate_kopecks']], $details);
        $this->assertStringNotContainsString('61234', json_encode($events));
        $this->asUser(User::factory()->create());
        $this->getJson($this->api.'/settings?month=2026-10&user_id='.$user->id)->assertJsonPath('revision', 0)->assertJsonPath('settings.line_rate_kopecks', 57348);
        $bad = array_replace($body, ['operation_id' => (string) Str::uuid(), 'settings' => ['rest_dates' => ['2026-11-01']]]);
        $this->patchJson($this->api.'/settings/2026-10', $bad)->assertUnprocessable();
        $bad['settings'] = ['night_from_minute' => 0];
        $this->patchJson($this->api.'/settings/2026-10', $bad)->assertUnprocessable();
    }

    public function test_month_transfer_requires_explicit_snapshot_refresh(): void
    {
        $user = User::factory()->create();
        $this->asUser($user);
        $shift = $this->createShift();
        $move = ['shift_date' => '2026-11-01', 'started_at' => '2026-11-01 09:00', 'ended_at' => '2026-11-01 17:00', 'expected_revision' => 1, 'operation_id' => (string) Str::uuid()];
        $this->putJson($this->api.'/shifts/'.$shift['id'], $move)->assertUnprocessable();
        $this->assertSame(1, WorkShift::find($shift['id'])->revision);
        $this->putJson($this->api.'/shifts/'.$shift['id'], $move + ['refresh_snapshot' => true])->assertOk()->assertJsonPath('shift.calculation_snapshot.month', '2026-11-01')->assertJsonPath('shift.revision', 2);
    }

    public function test_failed_overlap_rolls_back_revision_and_receipt(): void
    {
        $user = User::factory()->create();
        $this->asUser($user);
        $first = $this->createShift();
        $other = $this->postJson($this->api.'/shifts', $this->input('18:00', '20:00') + ['operation_id' => (string) Str::uuid()])->assertCreated()->json('shift');
        $uuid = (string) Str::uuid();
        $this->putJson($this->api.'/shifts/'.$other['id'], $this->input('16:00', '20:00') + ['operation_id' => $uuid, 'expected_revision' => 1])->assertUnprocessable();
        $this->assertSame(1, WorkShift::find($other['id'])->revision);
        $this->assertFalse(WorkTimeMutation::where('operation_id', $uuid)->exists());
        $this->assertSame(1, WorkShift::find($first['id'])->revision);
    }
}
