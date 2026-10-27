<?php

namespace Tests\Feature;

use App\Models\NaryadNorm;
use App\Models\User;
use App\Models\WorkShift;
use App\Models\WorkTimeMonthSettings;
use App\Services\ClickHouseService;
use App\Services\Payroll\PersonalWorkShiftService;
use App\Services\Payroll\PersonalWorkTimeMonth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PersonalWorkTimeMonthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        ClickHouseService::fake();
    }

    private function shift(User $user, string $start, string $end, ?string $code = null, string $kind = 'line'): WorkShift
    {
        return app(PersonalWorkShiftService::class)->create($user, [
            'shift_date' => substr($start, 0, 10), 'started_at' => $start, 'ended_at' => $end,
            'shift_code' => $code, 'segments' => [['kind' => $kind, 'started_at' => $start, 'ended_at' => $end]],
        ], (string) Str::uuid())->fresh();
    }

    public function test_pair_at_half_threshold_reprices_both_parts_without_writing_them(): void
    {
        $user = User::factory()->create();
        $a = $this->shift($user, '2026-10-27 16:00', '2026-10-28 00:00', '3+');
        $b = $this->shift($user, '2026-10-28 00:00', '2026-10-28 08:00', '1', 'reserve');
        $before = [$a->getAttributes(), $b->getAttributes()];
        $report = app(PersonalWorkTimeMonth::class)->summarize($user, '2026-10');
        $this->assertSame('all_night', $report['night_pairs'][0]['status']);
        $this->assertSame('2026-10-27 16:00:00', $report['shifts'][0]['started_at']);
        $this->assertSame('2026-10-28 00:00:00', $report['shifts'][0]['ended_at']);
        $this->assertSame('3+', $report['shifts'][0]['shift_code']);
        $this->assertSame([$a->id, $b->id], $report['night_pairs'][0]['shift_ids']);
        $this->assertSame(960, $report['totals']['paid_minutes']);
        $this->assertSame(960, $report['totals']['night_minutes']);
        $this->assertSame(480, $report['totals']['actual_night_minutes']);
        $this->assertSame(480, $report['by_kind_totals']['reserve']['paid_minutes']);
        $this->assertSame(1261658, $report['totals']['subtotal_kopecks']);
        $this->assertSame(0, $report['totals']['evening_minutes']);
        $this->assertSame(176960, $report['shifts'][1]['result']['by_kind']['reserve']['night_kopecks']);
        $this->assertNotContains('night_pair_incomplete', $report['warnings']);
        $this->assertSame($before, [$a->fresh()->getAttributes(), $b->fresh()->getAttributes()]);
        $this->assertSame($report, app(PersonalWorkTimeMonth::class)->summarize($user, '2026-10'));
        $this->assertFalse($report['calculation_complete']);
    }

    public function test_pair_below_half_keeps_individual_classification(): void
    {
        $user = User::factory()->create();
        $this->shift($user, '2026-10-27 16:00', '2026-10-28 00:00', '4+');
        $this->shift($user, '2026-10-28 04:00', '2026-10-28 08:00', '1');
        $report = app(PersonalWorkTimeMonth::class)->summarize($user, '2026-10');
        $this->assertSame('below_threshold', $report['night_pairs'][0]['status']);
        $this->assertSame(120, $report['shifts'][0]['result']['night_minutes']);
        $this->assertSame(360, $report['shifts'][0]['result']['evening_minutes']);
        $this->assertSame(720, $report['totals']['paid_minutes']);
    }

    public function test_gap_between_parts_is_not_paid_and_rest_after_pair_uses_both_parts(): void
    {
        $user = User::factory()->create();
        $this->shift($user, '2026-10-27 20:00', '2026-10-28 02:00', '3+');
        $this->shift($user, '2026-10-28 04:00', '2026-10-28 06:00', '1');
        $this->shift($user, '2026-10-29 18:00', '2026-10-29 20:00');
        $report = app(PersonalWorkTimeMonth::class)->summarize($user, '2026-10');
        $this->assertSame(600, $report['totals']['paid_minutes']);
        $this->assertCount(1, $report['rest_intervals']);
        $this->assertSame(960, $report['rest_intervals'][0]['required_minutes']);
        $this->assertEquals(2160, $report['rest_intervals'][0]['actual_minutes']);
    }

    public function test_pair_continues_across_month_with_each_part_counted_once_at_its_start_date(): void
    {
        $user = User::factory()->create();
        $a = $this->shift($user, '2026-10-31 16:00', '2026-11-01 00:00', '3+');
        WorkTimeMonthSettings::create(['user_id' => $user->id, 'month' => '2026-11-01', 'settings' => ['line_rate_kopecks' => 60000]]);
        $b = $this->shift($user, '2026-11-01 00:00', '2026-11-01 08:00', '1');
        $service = app(PersonalWorkTimeMonth::class);
        $october = $service->summarize($user, '2026-10');
        $november = $service->summarize($user, '2026-11');
        $this->assertSame([$a->id], array_column($october['shifts'], 'id'));
        $this->assertSame([$b->id], array_column($november['shifts'], 'id'));
        $this->assertSame(480, $october['totals']['night_minutes']);
        $this->assertSame(480, $november['totals']['night_minutes']);
        $this->assertSame(192000, $november['totals']['night_kopecks']);
    }

    public function test_rest_uses_previous_month_and_checks_long_combined_duty(): void
    {
        $user = User::factory()->create();
        $this->shift($user, '2026-10-30 16:00', '2026-10-31 00:00', '3+');
        $this->shift($user, '2026-10-31 00:00', '2026-10-31 08:00', '1');
        $this->shift($user, '2026-11-01 12:00', '2026-11-01 14:00');
        $r = app(PersonalWorkTimeMonth::class)->summarize($user, '2026-11')['rest_intervals'][0];
        $this->assertEquals(1680, $r['actual_minutes']);
        $this->assertSame(1920, $r['required_minutes']);
        $this->assertTrue($r['violation']);
    }

    public function test_missing_calendar_days_are_not_invented_as_rest_days(): void
    {
        $user = User::factory()->create();
        $this->shift($user, '2026-10-20 09:00', '2026-10-20 19:00');
        $this->shift($user, '2026-10-23 09:00', '2026-10-23 19:00');
        $service = app(PersonalWorkTimeMonth::class);
        $r = $service->summarize($user, '2026-10');
        $this->assertContains('rest_calendar_incomplete', $r['warnings']);
        $this->assertNull($r['rest_intervals'][0]['required_minutes']);
        $this->assertNull($r['rest_intervals'][0]['violation']);
        WorkTimeMonthSettings::create(['user_id' => $user->id, 'month' => '2026-10-01', 'settings' => ['rest_dates' => ['2026-10-21', '2026-10-22']]]);
        $r = $service->summarize($user, '2026-10');
        $this->assertNotContains('rest_calendar_incomplete', $r['warnings']);
        $this->assertSame(4080, $r['rest_intervals'][0]['required_minutes']);
        $this->assertTrue($r['rest_intervals'][0]['violation']);
    }

    public function test_different_users_and_wrong_shift_codes_do_not_form_pairs(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $a = $this->shift($user, '2026-10-27 16:00', '2026-10-28 00:00', '3+');
        $b = $this->shift($other, '2026-10-28 00:00', '2026-10-28 08:00', '1');
        $r = app(PersonalWorkTimeMonth::class)->summarize($user, '2026-10');
        $this->assertCount(0, $r['night_pairs']);
        $this->assertSame([$a->id], array_column($r['shifts'], 'id'));
        $this->assertNotContains($b->id, array_column($r['shifts'], 'id'));
        $this->shift($user, '2026-10-28 00:00', '2026-10-28 08:00', '2');
        $this->assertCount(0, app(PersonalWorkTimeMonth::class)->summarize($user, '2026-10')['night_pairs']);
    }

    public function test_merge_disabled_still_combines_duty_for_rest_without_repricing(): void
    {
        NaryadNorm::create(['year_hours' => 2000, 'month_hours' => 166, 'week_hours' => 48, 'min_rest_hours' => 8, 'planir' => ['merge_night' => '-']]);
        $user = User::factory()->create();
        $this->shift($user, '2026-10-27 16:00', '2026-10-28 00:00', '3+');
        $this->shift($user, '2026-10-28 00:00', '2026-10-28 08:00', '1');
        $this->shift($user, '2026-10-29 12:00', '2026-10-29 14:00');
        $r = app(PersonalWorkTimeMonth::class)->summarize($user, '2026-10');
        $this->assertSame('disabled', $r['night_pairs'][0]['status']);
        $this->assertSame(360, $r['totals']['evening_minutes']);
        $this->assertSame(1920, $r['rest_intervals'][0]['required_minutes']);
    }

    public function test_legacy_shifts_are_explicitly_excluded_from_new_money_totals(): void
    {
        $user = User::factory()->create();
        WorkShift::create(['user_id' => $user->id, 'type' => 'work', 'shift_date' => '2026-10-27', 'started_at' => '2026-10-27 09:00', 'ended_at' => '2026-10-27 17:00']);
        $this->shift($user, '2026-10-28 09:00', '2026-10-28 17:00');
        $r = app(PersonalWorkTimeMonth::class)->summarize($user, '2026-10');
        $this->assertSame(1, $r['legacy_shifts_count']);
        $this->assertContains('legacy_shifts_excluded', $r['warnings']);
        $this->assertSame(480, $r['totals']['paid_minutes']);
        $this->assertTrue($r['rest_intervals'][0]['legacy_context']);
    }

    public function test_empty_month_is_read_only_and_blocked_actor_is_rejected(): void
    {
        $user = User::factory()->create();
        $r = app(PersonalWorkTimeMonth::class)->summarize($user, '2026-10');
        $this->assertSame(0, $r['totals']['paid_minutes']);
        $this->assertDatabaseCount('work_time_month_settings', 0);
        $user->update(['is_active' => false]);
        $this->expectException(HttpException::class);
        app(PersonalWorkTimeMonth::class)->summarize($user, '2026-10');
    }

    public function test_incompatible_pair_snapshots_are_reported_without_choosing_one(): void
    {
        $user = User::factory()->create();
        $a = $this->shift($user, '2026-10-27 16:00', '2026-10-28 00:00', '3+');
        NaryadNorm::create(['year_hours' => 2000, 'month_hours' => 166, 'week_hours' => 48, 'min_rest_hours' => 8, 'planir' => ['merge_night' => '-']]);
        $b = $this->shift($user, '2026-10-28 00:00', '2026-10-28 08:00', '1');
        $r = app(PersonalWorkTimeMonth::class)->summarize($user, '2026-10');
        $this->assertSame('incompatible_settings', $r['night_pairs'][0]['status']);
        $this->assertContains('night_pair_settings_conflict', $r['warnings']);
        $this->assertSame($a->calculation_result['subtotal_kopecks'] + $b->calculation_result['subtotal_kopecks'], $r['totals']['subtotal_kopecks']);
    }

    public function test_nonadjacent_dates_and_default_five_plus_do_not_merge(): void
    {
        $user = User::factory()->create();
        $this->shift($user, '2026-10-20 16:00', '2026-10-21 00:00', '3+');
        $this->shift($user, '2026-10-22 00:00', '2026-10-22 08:00', '1');
        $this->shift($user, '2026-10-23 16:00', '2026-10-24 00:00', '5+');
        $this->shift($user, '2026-10-24 00:00', '2026-10-24 08:00', '1');
        $this->assertSame([], app(PersonalWorkTimeMonth::class)->summarize($user, '2026-10')['night_pairs']);
    }
}
