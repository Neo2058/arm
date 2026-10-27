<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WorkShift;
use App\Models\WorkTimeMonthSettings;
use App\Services\ClickHouseService;
use App\Services\Payroll\PersonalWorkShiftService;
use App\Services\Payroll\PersonalWorkTimeCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PersonalWorkTimeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        ClickHouseService::fake();
    }

    private function data(string $start = '09:00', string $end = '17:00', string $endDay = '2026-10-27'): array
    {
        return ['shift_date' => '2026-10-27', 'started_at' => '2026-10-27 '.$start, 'ended_at' => $endDay.' '.$end, 'break_duration' => 60];
    }

    public function test_paid_break_preview_and_saved_result_agree(): void
    {
        $user = User::factory()->create();
        $service = app(PersonalWorkShiftService::class);
        $preview = $service->preview($user, $this->data());
        $saved = $service->create($user, $this->data(), (string) Str::uuid())->fresh();
        $this->assertSame(480, $saved->total_minutes);
        $this->assertSame(60, $saved->calculation_result['paid_break_minutes']);
        $this->assertSame($preview->calculation_result, $saved->calculation_result);
        $this->assertSame(458784, $saved->calculation_result['by_kind']['line']['base_kopecks']);
        $this->assertSame(11470, $saved->calculation_result['by_kind']['line']['evening_kopecks']);
        $this->assertEquals(4702.54, $saved->estimated_earnings);
        $this->assertFalse($saved->calculation_result['calculation_complete']);
    }

    public function test_evening_and_inclusive_night_majority_boundaries(): void
    {
        $user = User::factory()->create();
        $service = app(PersonalWorkShiftService::class);
        foreach ([['16:00', '22:00', '2026-10-27', 360, 0], ['16:00', '00:00', '2026-10-28', 360, 120], ['18:00', '02:00', '2026-10-28', 0, 480], ['19:00', '03:00', '2026-10-28', 0, 480]] as [$start, $end, $day, $evening, $night]) {
            $r = $service->preview($user, $this->data($start, $end, $day))->calculation_result;
            $this->assertSame($evening, $r['evening_minutes']);
            $this->assertSame($night, $r['night_minutes']);
        }
        $r = $service->preview($user, $this->data('06:00', '16:00'))->calculation_result;
        $this->assertSame(0, $r['night_minutes']);
        $this->assertSame(0, $r['evening_minutes']);
    }

    public function test_rates_for_line_reserve_and_surface_are_not_mixed(): void
    {
        $data = $this->data('08:00', '11:00');
        $data['segments'] = [];
        foreach (['line', 'reserve', 'surface'] as $i => $kind) {
            $data['segments'][] = ['kind' => $kind, 'started_at' => '2026-10-27 '.sprintf('%02d:00', 8 + $i), 'ended_at' => '2026-10-27 '.sprintf('%02d:00', 9 + $i)];
        }
        $r = app(PersonalWorkShiftService::class)->preview(User::factory()->create(), $data)->calculation_result;
        $this->assertSame(163852, $r['subtotal_kopecks']);
        foreach (['line' => 57348, 'reserve' => 55300, 'surface' => 51204] as $kind => $rate) {
            $this->assertSame($rate, $r['by_kind'][$kind]['base_kopecks']);
        }
    }

    public function test_month_settings_and_old_snapshots_are_independent(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        WorkTimeMonthSettings::create(['user_id' => $other->id, 'month' => '2026-10-01', 'settings' => ['line_rate_kopecks' => 90000]]);
        $service = app(PersonalWorkShiftService::class);
        $shift = $service->create($user, $this->data(), (string) Str::uuid());
        $original = $shift->calculation_result;
        WorkTimeMonthSettings::create(['user_id' => $user->id, 'month' => '2026-10-01', 'settings' => ['line_rate_kopecks' => 60000]]);
        $shift->start_location = 'Changed description';
        $shift->save();
        $this->assertSame($original, $shift->fresh()->calculation_result);
        $this->assertSame(60000, $service->preview($user, $this->data())->calculation_snapshot['line_rate_kopecks']);
        $next = ['shift_date' => '2026-11-01', 'started_at' => '2026-11-01 09:00', 'ended_at' => '2026-11-01 17:00'];
        $this->assertSame(60000, $service->preview($user, $next)->calculation_snapshot['line_rate_kopecks']);
    }

    public function test_several_shifts_same_day_idempotency_and_owner(): void
    {
        $user = User::factory()->create();
        $service = app(PersonalWorkShiftService::class);
        $id = (string) Str::uuid();
        $a = $service->create($user, $this->data('08:00', '10:00'), $id);
        $again = $service->create($user, $this->data('08:00', '10:00'), $id);
        $this->assertSame($a->id, $again->id);
        $b = $service->create($user, $this->data('11:00', '13:00'), (string) Str::uuid());
        $this->assertNotSame($a->id, $b->id);
        $this->assertDatabaseCount('work_shifts', 2);
        $other = User::factory()->create();
        $c = $service->create($other, $this->data('08:00', '10:00'), $id);
        $this->assertSame($other->id, $c->user_id);
    }

    public function test_uuid_cannot_be_reused_with_different_data(): void
    {
        $user = User::factory()->create();
        $service = app(PersonalWorkShiftService::class);
        $uuid = (string) Str::uuid();
        $service->create($user, $this->data(), $uuid);
        $this->expectException(HttpException::class);
        $service->create($user, $this->data('10:00', '18:00'), $uuid);
    }

    public function test_overlapping_shifts_are_rejected_but_adjacent_are_allowed(): void
    {
        $user = User::factory()->create();
        $service = app(PersonalWorkShiftService::class);
        $service->create($user, $this->data('08:00', '10:00'), (string) Str::uuid());
        $service->create($user, $this->data('10:00', '12:00'), (string) Str::uuid());
        $this->expectException(ValidationException::class);
        $service->create($user, $this->data('11:00', '13:00'), (string) Str::uuid());
    }

    public function test_invalid_times_breaks_and_segment_gaps_do_not_save(): void
    {
        $service = app(PersonalWorkShiftService::class);
        $user = User::factory()->create();
        foreach ([['ended_at' => '2026-10-27 08:00'], ['break_duration' => -1], ['break_duration' => 1000], ['shift_date' => '2026-10-26'], ['segments' => [['kind' => 'line', 'started_at' => '2026-10-27 10:00', 'ended_at' => '2026-10-27 17:00']]]] as $change) {
            try {
                $service->create($user, array_replace($this->data(), $change), (string) Str::uuid());
                $this->fail('Invalid shift accepted');
            } catch (ValidationException) {
                $this->assertDatabaseCount('work_shifts', 0);
            }
        }
    }

    public function test_exact_minutes_survive_rounding_and_month_boundary(): void
    {
        $user = User::factory()->create();
        $data = ['shift_date' => '2026-10-31', 'started_at' => '2026-10-31 23:00', 'ended_at' => '2026-11-01 07:20'];
        $shift = app(PersonalWorkShiftService::class)->create($user, $data, (string) Str::uuid());
        $this->assertSame(500, $shift->total_minutes);
        $this->assertSame(475988, $shift->calculation_result['by_kind']['line']['base_kopecks']);
        $this->assertSame('2026-10-01', $shift->calculation_snapshot['month']);
        $tiny = ['shift_date' => '2026-10-27', 'started_at' => '2026-10-27 09:00', 'ended_at' => '2026-10-27 09:01'];
        $this->assertFalse(app(PersonalWorkShiftService::class)->preview($user, $tiny)->calculation_result['all_night']);
    }

    public function test_rest_reuses_planir_and_does_not_block_actual_short_rest(): void
    {
        $user = User::factory()->create();
        $service = app(PersonalWorkShiftService::class);
        $snapshot = $service->preview($user, $this->data())->calculation_snapshot;
        $calculator = app(PersonalWorkTimeCalculator::class);
        $end = CarbonImmutable::parse('2026-10-31 18:00', 'Europe/Moscow');
        $next = $end->addHours(13);
        $r = $calculator->rest($end, $next, 480, 0, $snapshot);
        $this->assertSame(960, $r['required_minutes']);
        $this->assertTrue($r['violation']);
        $this->assertSame(2640, $calculator->rest($end, $next, 600, 1, $snapshot)['required_minutes']);
        $this->assertSame(4080, $calculator->rest($end, $next, 600, 2, $snapshot)['required_minutes']);
    }

    public function test_legacy_and_new_saves_cannot_change_calculation_versions(): void
    {
        $user = User::factory()->create();
        $legacy = WorkShift::create($this->data() + ['user_id' => $user->id, 'type' => 'work']);
        $legacy->refresh();
        $this->assertSame(1, $legacy->calculation_version);
        $this->assertSame(420, $legacy->total_minutes);
        $this->assertEquals(3150, $legacy->estimated_earnings);
        $this->assertNull($legacy->calculation_snapshot);
        $legacy->calculation_version = 2;
        $this->expectException(ValidationException::class);
        $legacy->save();
    }

    public function test_legacy_endpoint_does_not_replace_a_new_version_shift(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $new = app(PersonalWorkShiftService::class)->create($user, $this->data(), (string) Str::uuid());
        $this->actingAs($user)->postJson('/api/work-shifts', $this->data() + ['type' => 'work'])->assertConflict();
        $this->assertDatabaseCount('work_shifts', 1);
        $this->assertSame(480, $new->fresh()->total_minutes);
        $this->assertSame(2, $new->fresh()->calculation_version);
        $this->getJson('/api/work-shifts?month=10&year=2026')->assertOk()->assertJsonCount(0, 'shifts');
    }

    public function test_replayed_operation_keeps_original_snapshot_after_settings_change(): void
    {
        $user = User::factory()->create();
        $service = app(PersonalWorkShiftService::class);
        $uuid = (string) Str::uuid();
        $original = $service->create($user, $this->data(), $uuid);
        WorkTimeMonthSettings::create(['user_id' => $user->id, 'month' => '2026-10-01', 'settings' => ['line_rate_kopecks' => 99999]]);
        $retry = $service->create($user, $this->data(), $uuid);
        $this->assertSame($original->calculation_result, $retry->calculation_result);
        $this->assertDatabaseCount('work_shifts', 1);
    }

    public function test_unknown_rules_and_client_supplied_results_cannot_change_totals(): void
    {
        $user = User::factory()->create();
        $data = $this->data() + ['user_id' => 9999, 'calculation_version' => 1, 'total_minutes' => 5, 'calculation_snapshot' => ['line_rate_kopecks' => 1]];
        $shift = app(PersonalWorkShiftService::class)->create($user, $data, (string) Str::uuid());
        $this->assertSame($user->id, $shift->user_id);
        $this->assertSame(480, $shift->total_minutes);
        $this->assertSame(57348, $shift->calculation_snapshot['line_rate_kopecks']);
        $shift->calculation_version = 3;
        $this->expectException(ValidationException::class);
        $shift->save();
    }

    public function test_blocked_account_cannot_preview_or_create(): void
    {
        $user = User::factory()->create(['is_active' => false]);
        $this->expectException(HttpException::class);
        app(PersonalWorkShiftService::class)->create($user, $this->data(), (string) Str::uuid());
    }

    public function test_migration_preserves_preexisting_manual_totals(): void
    {
        $user = User::factory()->create();
        $migration = require database_path('migrations/2026_10_27_100000_version_personal_work_shifts.php');
        $migration->down();
        $id = DB::table('work_shifts')->insertGetId([
            'user_id' => $user->id, 'type' => 'work', 'shift_date' => '2026-09-01',
            'started_at' => '2026-09-01 09:00', 'ended_at' => '2026-09-01 17:00',
            'break_duration' => 40, 'total_minutes' => 123, 'estimated_earnings' => 987.65,
        ]);
        $migration->up();
        $stored = WorkShift::findOrFail($id);
        $this->assertSame(123, $stored->total_minutes);
        $this->assertEquals(987.65, $stored->estimated_earnings);
        $this->assertSame(1, $stored->calculation_version);
        $this->assertNull($stored->calculation_result);
    }
}
