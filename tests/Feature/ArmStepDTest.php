<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckDeviceBinding;
use App\Http\Middleware\CheckDynamicBarrier;
use App\Models\ArmAccount;
use App\Models\ArmAppointment;
use App\Models\ArmHoliday;
use App\Models\ArmPersonnel;
use App\Models\ArmShiftBreakdown;
use App\Models\NaryadAssignment;
use App\Models\NaryadNorm;
use App\Models\User;
use App\Services\Arm\DokmenuReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ArmStepDTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->withoutMiddleware([
            CheckDeviceBinding::class,
            CheckDynamicBarrier::class,
        ]);
        NaryadNorm::create([
            'year_hours' => 5000,
            'month_hours' => 400,
            'week_hours' => 80,
            'min_rest_hours' => 8,
        ]);
    }

    public function test_operator_opens_reports_menu_and_hours(): void
    {
        $operator = User::factory()->operator()->create();
        $this->actingAs($operator)->get('/uchet/reports')->assertOk()->assertSee('Затраты рабочего времени');
        $this->actingAs($operator)->get('/uchet/reports/hours?month=2026-04')->assertOk();
        $this->actingAs($operator)->get('/uchet/reports/missing')->assertNotFound();
    }

    public function test_time_costs_counts_line_and_sunday_rest_as_kodvih(): void
    {
        $mash = $this->person('1001', 'МШ', 'ИВАНОВ');
        $pom = $this->person('1002', 'П/М', 'ПЕТРОВ');
        ArmHoliday::create(['holiday_date' => '2026-04-08', 'name' => 'тест']);
        NaryadAssignment::create([
            'user_id' => $mash->id,
            'plan_date' => '2026-04-02',
            'route_number' => '25',
            'work_code' => '25',
            'shift_code' => '2',
            'hours_line' => 8,
        ]);
        NaryadAssignment::create([
            'user_id' => $pom->id,
            'plan_date' => '2026-04-03',
            'route_number' => 'Б',
            'work_code' => 'Б',
            'shift_code' => null,
        ]);
        NaryadAssignment::create([
            'user_id' => $mash->id,
            'plan_date' => '2026-04-06',
            'route_number' => 'ОТ',
            'work_code' => 'ОТ',
            'shift_code' => null,
        ]);

        $report = app(DokmenuReportService::class)->timeCosts('2026-04');
        $byCode = collect($report['blocks'])->keyBy('code');
        $this->assertSame(1, $byCode['ЛИН']['mash'][2]);
        $this->assertSame(1, $byCode['ЛИН']['mash_total']);
        $this->assertSame(1, $byCode['Б']['pom'][3]);
        $this->assertSame(1, $byCode['ОТ']['mash'][6]);
        $this->assertSame(0, $byCode['Е']['mash_total']);
    }

    public function test_sunday_empty_shift_goes_to_rest_code_not_sick(): void
    {
        $mash = $this->person('1100', 'МШ', 'СИДОРОВ');
        NaryadAssignment::create([
            'user_id' => $mash->id,
            'plan_date' => '2026-04-05',
            'route_number' => 'Б',
            'work_code' => 'Б',
            'shift_code' => null,
        ]);

        $report = app(DokmenuReportService::class)->timeCosts('2026-04');
        $byCode = collect($report['blocks'])->keyBy('code');
        $this->assertSame(1, $byCode['Е']['mash'][5]);
        $this->assertFalse($byCode->has('Б') && $byCode['Б']['mash_total'] > 0);
    }

    public function test_disability_skips_sunday_and_holiday(): void
    {
        $mash = $this->person('1200', 'МШ', 'БОЛЬНОВ');
        ArmHoliday::create(['holiday_date' => '2026-04-07', 'name' => 'праздник']);
        NaryadAssignment::create([
            'user_id' => $mash->id,
            'plan_date' => '2026-04-06',
            'route_number' => 'Б',
            'work_code' => 'Б',
        ]);
        NaryadAssignment::create([
            'user_id' => $mash->id,
            'plan_date' => '2026-04-05',
            'route_number' => 'Б',
            'work_code' => 'Б',
        ]);
        NaryadAssignment::create([
            'user_id' => $mash->id,
            'plan_date' => '2026-04-07',
            'route_number' => 'Т',
            'work_code' => 'Т',
        ]);
        NaryadAssignment::create([
            'user_id' => $mash->id,
            'plan_date' => '2026-04-08',
            'route_number' => 'Т',
            'work_code' => 'Т',
        ]);

        $report = app(DokmenuReportService::class)->disability('2026-04', '2026-04');
        $this->assertCount(1, $report['rows']);
        $this->assertSame(1, $report['rows'][0]['sick'][4]);
        $this->assertSame(1, $report['rows'][0]['injury'][4]);
        $this->assertSame(1, $report['total_sick']);
        $this->assertSame(1, $report['total_injury']);
    }

    public function test_brigadiers_and_class_counts(): void
    {
        $this->person('1301', 'МШ', 'БРИГ', ['is_brigadier' => true, 'class_code' => '1']);
        $this->person('1302', 'П/М', 'ПОМ', ['class_code' => '0']);
        $this->person('1303', 'МШ', 'СОВМ', ['class_code' => '1', 'shop_code' => '11']);
        $fired = $this->person('1304', 'МШ', 'УВОЛ', ['is_brigadier' => true, 'class_code' => '1']);
        ArmPersonnel::query()->where('user_id', $fired->id)->update(['fired_on' => '2026-03-01']);

        $asOf = \Carbon\Carbon::parse('2026-04-15');
        $brig = app(DokmenuReportService::class)->brigadiers($asOf);
        $this->assertCount(1, $brig['rows']);
        $this->assertSame('1301', $brig['rows'][0]['tab']);

        $kl = app(DokmenuReportService::class)->classCounts($asOf);
        $mash1 = collect($kl['rows'])->first(fn ($r) => $r['position'] === 'МШ' && $r['class'] === '1');
        $this->assertSame(1, $mash1['count']);
        $this->assertSame(1, $mash1['combined']);
    }

    public function test_night_shifts_use_3_and_4_not_5(): void
    {
        $mash = $this->person('1401', 'МШ', 'НОЧНОЙ');
        NaryadAssignment::create(['user_id' => $mash->id, 'plan_date' => '2026-04-01', 'route_number' => '08', 'shift_code' => '3+']);
        NaryadAssignment::create(['user_id' => $mash->id, 'plan_date' => '2026-04-02', 'route_number' => '08', 'shift_code' => '4+']);
        NaryadAssignment::create(['user_id' => $mash->id, 'plan_date' => '2026-04-03', 'route_number' => '08', 'shift_code' => '5+']);
        NaryadAssignment::create(['user_id' => $mash->id, 'plan_date' => '2026-04-04', 'route_number' => '08', 'shift_code' => '2']);

        $report = app(DokmenuReportService::class)->nightShifts('2026-04');
        $this->assertSame(2, $report['rows'][0]['count']);
    }

    public function test_extra_marks_count_prim1_on_breakdown(): void
    {
        $mash = $this->person('1501', 'МШ', 'УЧЕБА');
        $bd = ArmShiftBreakdown::create([
            'graph_code' => '01',
            'route_code' => '25',
            'shift_code' => '2',
            'sequence' => '1',
            'extra_mark' => 'т',
        ]);
        NaryadAssignment::create([
            'user_id' => $mash->id,
            'plan_date' => '2026-04-10',
            'route_number' => '25',
            'work_code' => '25',
            'shift_code' => '2',
            'arm_shift_breakdown_id' => $bd->id,
        ]);
        NaryadAssignment::create([
            'user_id' => $mash->id,
            'plan_date' => '2026-04-11',
            'route_number' => '12',
            'work_code' => '12',
            'shift_code' => '1',
        ]);

        $with = app(DokmenuReportService::class)->extraMarks('2026-04', 'т', 'with');
        $this->assertCount(1, $with['rows']);
        $this->assertSame(1, $with['rows'][0]['count']);
        $without = app(DokmenuReportService::class)->extraMarks('2026-04', 'т', 'without');
        $this->assertCount(0, $without['rows']);
    }

    public function test_overtime_reads_primary_account_per1(): void
    {
        $mash = $this->person('1601', 'МШ', 'ПЕРЕР');
        ArmAccount::create([
            'user_id' => $mash->id,
            'year_month' => '2026-04',
            'kind' => 'primary',
            'ls_number' => '0',
            'position_code' => 'МШ',
            'totals' => ['per1' => 6.5],
        ]);
        ArmAccount::create([
            'user_id' => $mash->id,
            'year_month' => '2026-04',
            'kind' => 'repeat',
            'ls_number' => '1',
            'position_code' => 'МШ',
            'totals' => ['per1' => 9],
        ]);

        $report = app(DokmenuReportService::class)->overtime('2026-04');
        $this->assertCount(1, $report['rows']);
        $this->assertEquals(6.5, $report['rows'][0]['hours']);
    }

    public function test_work_breaks_need_twelve_empty_shift_rows(): void
    {
        $mash = $this->person('1701', 'МШ', 'ПЕРЕРЫВ');
        for ($d = 1; $d <= 12; $d++) {
            NaryadAssignment::create([
                'user_id' => $mash->id,
                'plan_date' => sprintf('2026-03-%02d', $d),
                'route_number' => 'ОТ',
                'work_code' => 'ОТ',
            ]);
        }
        NaryadAssignment::create([
            'user_id' => $mash->id,
            'plan_date' => '2026-04-01',
            'route_number' => '25',
            'work_code' => '25',
            'shift_code' => '2',
        ]);

        $hit = app(DokmenuReportService::class)->workBreaks(\Carbon\Carbon::parse('2026-04-01'));
        $this->assertCount(1, $hit['rows']);
        $this->assertSame('ОТ', $hit['rows'][0]['kinds']);

        $miss = app(DokmenuReportService::class)->workBreaks(\Carbon\Carbon::parse('2026-03-20'));
        $this->assertCount(0, $miss['rows']);
    }

    public function test_holiday_hours_and_special_marks(): void
    {
        $mash = $this->person('1801', 'МШ', 'ПРАЗД');
        NaryadAssignment::create([
            'user_id' => $mash->id,
            'plan_date' => '2026-04-08',
            'route_number' => '25',
            'work_code' => '25',
            'shift_code' => '2',
            'hours_holiday' => 3.5,
            'hours_holiday_reserve' => 1,
            'hours_holiday_2' => 2,
            'mark_code' => 'К',
            'registered' => true,
        ]);

        $h = app(DokmenuReportService::class)->holidayHours(
            \Carbon\Carbon::parse('2026-04-01'),
            \Carbon\Carbon::parse('2026-04-30')
        );
        $this->assertSame(3.5, $h['rows'][0]['p1']);
        $this->assertSame(1.0, $h['rows'][0]['reserve']);
        $this->assertSame(2.0, $h['rows'][0]['p2']);

        $m = app(DokmenuReportService::class)->specialMarks(
            \Carbon\Carbon::parse('2026-04-01'),
            \Carbon\Carbon::parse('2026-04-30'),
            'К'
        );
        $this->assertCount(1, $m['groups']);
        $this->assertSame('К', $m['groups'][0]['rows'][0]['mark']);
    }

    public function test_appointments_list_person_days(): void
    {
        $mash = $this->person('1901', 'МШ', 'НАЗН');
        ArmAppointment::create([
            'user_id' => $mash->id,
            'tab_number' => '1901',
            'appointed_on' => '2026-01-01',
            'position_code' => 'МШ',
        ]);
        NaryadAssignment::create([
            'user_id' => $mash->id,
            'plan_date' => '2026-04-03',
            'route_number' => '08',
            'work_code' => '08',
            'shift_code' => '1',
            'hours_total' => 7.4,
        ]);

        $report = app(DokmenuReportService::class)->appointments(
            $mash->id,
            \Carbon\Carbon::parse('2026-04-01'),
            \Carbon\Carbon::parse('2026-04-30')
        );
        $this->assertCount(1, $report['rows']);
        $this->assertSame('08.1', $report['rows'][0]['route']);
    }

    public function test_hours_page_shows_line_and_otvm_period(): void
    {
        $operator = User::factory()->operator()->create();
        $mash = $this->person('2001', 'МШ', 'ЧАСЫ');
        NaryadAssignment::create([
            'user_id' => $mash->id,
            'plan_date' => '2026-04-03',
            'route_number' => '25',
            'hours_line' => 8.2,
            'hours_reserve' => 1.1,
        ]);
        \App\Models\ArmAbsence::create([
            'user_id' => $mash->id,
            'tab_number' => '2001',
            'starts_on' => '2026-04-10',
            'ends_on' => '2026-04-12',
            'kind_code' => 'ОТ',
        ]);

        $this->actingAs($operator)
            ->get('/uchet/reports/hours?month=2026-04')
            ->assertOk()
            ->assertSee('8.2')
            ->assertSee('ОТ');
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function person(string $tab, string $position, string $name, array $extra = []): User
    {
        $user = User::factory()->driver()->create(['name' => $name]);
        ArmPersonnel::create(array_merge([
            'user_id' => $user->id,
            'tab_number' => $tab,
            'full_name' => $name,
            'position_code' => $position,
            'class_code' => '1',
            'hired_on' => '2020-01-01',
        ], $extra));

        return $user;
    }
}
