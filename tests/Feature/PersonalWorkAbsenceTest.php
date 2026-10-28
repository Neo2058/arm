<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckDeviceBinding;
use App\Http\Middleware\CheckDynamicBarrier;
use App\Models\User;
use App\Models\WorkShift;
use App\Services\ClickHouseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tests\TestCase;

class PersonalWorkAbsenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        ClickHouseService::fake();
        $this->withoutMiddleware([CheckDeviceBinding::class, CheckDynamicBarrier::class]);
    }

    private function actor(): User
    {
        Auth::forgetGuards();
        $user = User::factory()->create(['is_active' => true]);
        $this->withToken($user->createToken('absence-test')->plainTextToken);

        return $user;
    }

    private function period(string $from = '2026-10-30', string $to = '2026-11-02', string $kind = 'sick'): array
    {
        return ['operation_id' => (string) Str::uuid(), 'type' => 'deviation', 'absence_kind' => $kind, 'shift_date' => $from, 'absence_end' => $to];
    }

    private function work(string $date): array
    {
        return ['operation_id' => (string) Str::uuid(), 'shift_date' => $date, 'started_at' => $date.' 08:00', 'ended_at' => $date.' 16:00'];
    }

    public function test_period_crosses_months_without_charging_hours_or_money_and_replays_exactly(): void
    {
        $user = $this->actor();
        $input = $this->period();
        $a = $this->postJson('/api/mobile/worktime/shifts', $input + ['total_minutes' => 999, 'estimated_earnings' => 999]);
        $a->assertCreated()->assertJsonPath('shift.type', 'deviation')->assertJsonPath('shift.calculation_result.payment_status', 'not_calculated');
        $this->postJson('/api/mobile/worktime/shifts', $input)->assertExactJson($a->json());
        $this->assertDatabaseCount('work_shifts', 1);
        $row = WorkShift::first();
        $this->assertSame(0, $row->total_minutes);
        $this->assertEquals(0, $row->estimated_earnings);
        $this->assertSame('2026-11-03 00:00:00', $row->getRawOriginal('ended_at'));
        foreach (['2026-10', '2026-11'] as $month) {
            $this->getJson('/api/mobile/worktime/month?month='.$month)->assertOk()
                ->assertJsonPath('totals.paid_minutes', 0)->assertJsonPath('totals.subtotal_kopecks', 0)
                ->assertJsonPath('absences.0.days_in_month', 2)->assertJsonPath('absences.0.id', $row->id)
                ->assertJsonPath('excluded_deviations_count', 0);
        }
        $this->assertSame($user->id, $row->user_id);
        $this->assertDatabaseCount('deviations_catalog', 0);
    }

    public function test_all_three_kinds_and_single_day_period_are_supported(): void
    {
        $this->actor();
        foreach (['sick', 'leave', 'study'] as $i => $kind) {
            $date = '2026-10-'.(20 + $i);
            $this->postJson('/api/mobile/worktime/shifts', $this->period($date, $date, $kind))->assertCreated()->assertJsonPath('shift.absence_kind', $kind);
        }
        $this->getJson('/api/mobile/worktime/month?month=2026-10')->assertJsonCount(3, 'absences')->assertJsonPath('absences.0.days_in_month', 1);
    }

    public function test_overlap_is_rejected_both_ways_but_adjacent_days_are_allowed(): void
    {
        $this->actor();
        $this->postJson('/api/mobile/worktime/shifts', $this->work('2026-10-28'))->assertCreated();
        $this->postJson('/api/mobile/worktime/shifts', $this->period('2026-10-27', '2026-10-29'))->assertUnprocessable();
        $this->postJson('/api/mobile/worktime/shifts', $this->period('2026-10-29', '2026-11-02'))->assertCreated();
        $this->postJson('/api/mobile/worktime/shifts', $this->work('2026-10-31'))->assertUnprocessable();
        $this->postJson('/api/mobile/worktime/shifts', $this->period('2026-11-01', '2026-11-04'))->assertUnprocessable();
        $this->postJson('/api/mobile/worktime/shifts', $this->work('2026-11-03'))->assertCreated();
        $this->assertDatabaseCount('work_shifts', 3);
    }

    public function test_revision_owner_and_immutable_receipts_protect_period_edits(): void
    {
        $owner = $this->actor();
        $input = $this->period();
        $original = $this->postJson('/api/mobile/worktime/shifts', $input)->assertCreated()->json();
        $id = $original['shift']['id'];
        $edit = $this->period('2026-10-30', '2026-11-04', 'leave') + ['expected_revision' => 1];
        $saved = $this->putJson('/api/mobile/worktime/shifts/'.$id, $edit)->assertOk()->assertJsonPath('shift.revision', 2)->json();
        $this->putJson('/api/mobile/worktime/shifts/'.$id, $edit)->assertExactJson($saved);
        $this->postJson('/api/mobile/worktime/shifts', $input)->assertExactJson($original);
        $this->putJson('/api/mobile/worktime/shifts/'.$id, $this->period() + ['expected_revision' => 1])->assertConflict();
        $this->actor();
        $this->getJson('/api/mobile/worktime/shifts/'.$id)->assertNotFound();
        $this->putJson('/api/mobile/worktime/shifts/'.$id, $edit)->assertNotFound();
        $this->getJson('/api/mobile/worktime/month?month=2026-10')->assertJsonCount(0, 'absences');
        $owner->update(['is_active' => false]);
        Auth::forgetGuards();
        $this->withToken($owner->createToken('blocked-test')->plainTextToken);
        $this->postJson('/api/mobile/worktime/shifts', $this->period())->assertForbidden();
    }

    public function test_invalid_dates_kind_and_type_conversion_are_rejected(): void
    {
        $this->actor();
        foreach ([$this->period('2026-02-30', '2026-03-01'), $this->period('2026-10-30', '2026-10-29'), $this->period('2026-01-01', '2027-12-01'), $this->period('2026-10-30', '2026-10-30', 'other')] as $bad) {
            $this->postJson('/api/mobile/worktime/shifts', $bad)->assertUnprocessable();
        }
        $id = $this->postJson('/api/mobile/worktime/shifts', $this->period())->assertCreated()->json('shift.id');
        $this->putJson('/api/mobile/worktime/shifts/'.$id, $this->work('2026-10-30') + ['expected_revision' => 1])->assertUnprocessable();
        $this->assertDatabaseCount('work_shifts', 1);
    }

    public function test_period_is_not_treated_as_regular_intershift_rest_and_blocks_legacy_writes(): void
    {
        $user = $this->actor();
        $this->postJson('/api/mobile/worktime/shifts', $this->work('2026-10-26'))->assertCreated();
        $this->postJson('/api/mobile/worktime/shifts', $this->work('2026-11-03'))->assertCreated();
        $this->postJson('/api/mobile/worktime/shifts', $this->period('2026-10-27', '2026-11-02'))->assertCreated();
        $this->getJson('/api/mobile/worktime/month?month=2026-10')->assertOk()
            ->assertJsonPath('rest_intervals.0.absence_context', true)->assertJsonPath('rest_intervals.0.violation', null);
        Auth::forgetGuards();
        $this->withHeader('Authorization', '')->actingAs($user, 'web')->postJson('/api/work-shifts', $this->work('2026-10-31') + ['type' => 'work'])->assertConflict();
    }
}
