<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckDeviceBinding;
use App\Http\Middleware\CheckDynamicBarrier;
use App\Models\ArmHoliday;
use App\Models\ArmShiftBreakdown;
use App\Models\NaryadAssignment;
use App\Models\NaryadNorm;
use App\Models\NaryadQuota;
use App\Models\ScheduleType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\Support\DbfWriter;
use Tests\TestCase;

class ArmImportAndPlanningHoursTest extends TestCase
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
    }

    public function test_import_dbf_loads_graphs_holidays_and_calendar(): void
    {
        $dir = sys_get_temp_dir().'/arm_dbf_'.uniqid();
        mkdir($dir);

        DbfWriter::write($dir.'/GRAF.DBF', [
            ['name' => 'KODEL', 'type' => 'C', 'length' => 2],
            ['name' => 'NAZEL', 'type' => 'C', 'length' => 15],
            ['name' => 'ZVET', 'type' => 'C', 'length' => 2],
            ['name' => 'PERNM', 'type' => 'C', 'length' => 1],
        ], [
            ['KODEL' => '01', 'NAZEL' => 'Рабочий', 'ZVET' => '1', 'PERNM' => '+'],
        ]);

        DbfWriter::write($dir.'/PRAZD.DBF', [
            ['name' => 'KODEL', 'type' => 'D', 'length' => 8],
            ['name' => 'NAZEL', 'type' => 'C', 'length' => 30],
        ], [
            ['KODEL' => '20260101', 'NAZEL' => 'Новый год'],
        ]);

        DbfWriter::write($dir.'/KALEND.DBF', [
            ['name' => 'DAT', 'type' => 'D', 'length' => 8],
            ['name' => 'TIPGR', 'type' => 'C', 'length' => 2],
        ], [
            ['DAT' => '20260115', 'TIPGR' => '01'],
        ]);

        DbfWriter::write($dir.'/RAZBSM.DBF', [
            ['name' => 'GRAF', 'type' => 'C', 'length' => 2],
            ['name' => 'NM', 'type' => 'C', 'length' => 2],
            ['name' => 'SM', 'type' => 'C', 'length' => 2],
            ['name' => 'DOL', 'type' => 'C', 'length' => 1],
            ['name' => 'NACH', 'type' => 'N', 'length' => 5, 'decimal' => 2],
            ['name' => 'OKON', 'type' => 'N', 'length' => 5, 'decimal' => 2],
            ['name' => 'CHAS', 'type' => 'N', 'length' => 5, 'decimal' => 2],
            ['name' => 'LIN1', 'type' => 'N', 'length' => 5, 'decimal' => 2],
            ['name' => 'LIN2', 'type' => 'N', 'length' => 5, 'decimal' => 2],
            ['name' => 'CHASR1', 'type' => 'N', 'length' => 5, 'decimal' => 2],
            ['name' => 'CHASR2', 'type' => 'N', 'length' => 5, 'decimal' => 2],
            ['name' => 'NOCH1', 'type' => 'N', 'length' => 5, 'decimal' => 2],
            ['name' => 'NOCHR1', 'type' => 'N', 'length' => 5, 'decimal' => 2],
            ['name' => 'NOCH2', 'type' => 'N', 'length' => 5, 'decimal' => 2],
            ['name' => 'NOCHR2', 'type' => 'N', 'length' => 5, 'decimal' => 2],
            ['name' => 'VECH1', 'type' => 'N', 'length' => 5, 'decimal' => 2],
            ['name' => 'VECHR1', 'type' => 'N', 'length' => 5, 'decimal' => 2],
            ['name' => 'VECH2', 'type' => 'N', 'length' => 5, 'decimal' => 2],
            ['name' => 'VECHR2', 'type' => 'N', 'length' => 5, 'decimal' => 2],
            ['name' => 'RAZR1', 'type' => 'N', 'length' => 5, 'decimal' => 2],
            ['name' => 'RAZRR1', 'type' => 'N', 'length' => 5, 'decimal' => 2],
            ['name' => 'RAZR2', 'type' => 'N', 'length' => 5, 'decimal' => 2],
            ['name' => 'RAZRR2', 'type' => 'N', 'length' => 5, 'decimal' => 2],
            ['name' => 'PLZAST', 'type' => 'C', 'length' => 8],
            ['name' => 'PLOKON', 'type' => 'C', 'length' => 8],
            ['name' => 'SODER', 'type' => 'C', 'length' => 25],
            ['name' => 'UTRO', 'type' => 'C', 'length' => 2],
            ['name' => 'SMUT', 'type' => 'C', 'length' => 2],
            ['name' => 'NOM', 'type' => 'C', 'length' => 3],
            ['name' => 'PRIM', 'type' => 'C', 'length' => 20],
        ], [
            [
                'GRAF' => '01', 'NM' => '25', 'SM' => '1', 'DOL' => 'М', 'NOM' => '1',
                'NACH' => '5.30', 'OKON' => '13.10', 'CHAS' => '7.40', 'LIN1' => '6.20',
                'LIN2' => 0, 'CHASR1' => 0, 'CHASR2' => 0, 'NOCH1' => '1.20', 'NOCHR1' => 0,
                'NOCH2' => 0, 'NOCHR2' => 0, 'VECH1' => 0, 'VECHR1' => 0, 'VECH2' => 0, 'VECHR2' => 0,
                'RAZR1' => 0, 'RAZRR1' => 0, 'RAZR2' => 0, 'RAZRR2' => 0,
                'PLZAST' => '', 'PLOKON' => '', 'SODER' => 'линия', 'UTRO' => '', 'SMUT' => '', 'PRIM' => '',
            ],
        ]);

        $code = Artisan::call('arm:import-dbf', ['path' => $dir]);
        $this->assertSame(0, $code);

        $this->assertDatabaseHas('schedule_types', ['foxpro_code' => '01', 'name' => 'Рабочий']);
        $this->assertTrue(
            ArmHoliday::query()->whereDate('holiday_date', '2026-01-01')->where('name', 'Новый год')->exists()
        );
        $this->assertTrue(NaryadQuota::whereDate('plan_date', '2026-01-15')->exists());
        $this->assertDatabaseHas('arm_shift_breakdowns', [
            'graph_code' => '01',
            'route_code' => '25',
            'shift_code' => '1',
            'hours_total' => 7.4,
        ]);
    }

    public function test_import_nrdpar_merges_planir_and_keeps_early_window(): void
    {
        NaryadNorm::create([
            'year_hours' => 2000,
            'month_hours' => 166,
            'week_hours' => 48,
            'min_rest_hours' => 8,
        ]);
        $dir = sys_get_temp_dir().'/arm_nrdpar_'.uniqid();
        mkdir($dir);
        DbfWriter::write($dir.'/NRDPAR.DBF', [
            ['name' => 'NR_12', 'type' => 'C', 'length' => 2],
            ['name' => 'NR_1VIH', 'type' => 'N', 'length' => 3],
            ['name' => 'NR_2VIH', 'type' => 'N', 'length' => 3],
            ['name' => 'NR_MINV', 'type' => 'N', 'length' => 3],
            ['name' => 'NR_NOVIH', 'type' => 'N', 'length' => 3],
            ['name' => 'NR_DOPPR', 'type' => 'N', 'length' => 5, 'decimal' => 2],
            ['name' => 'NR_LINIA', 'type' => 'N', 'length' => 1],
            ['name' => 'NR_PRAZD', 'type' => 'C', 'length' => 1],
            ['name' => 'NR_KINT', 'type' => 'N', 'length' => 5, 'decimal' => 2],
            ['name' => 'NR_NMN', 'type' => 'N', 'length' => 3],
            ['name' => 'NR_NMK', 'type' => 'N', 'length' => 3],
            ['name' => 'NR_NSM1', 'type' => 'C', 'length' => 2],
            ['name' => 'NR_NSM2', 'type' => 'C', 'length' => 2],
            ['name' => 'NR_2RAN1', 'type' => 'N', 'length' => 5, 'decimal' => 2],
            ['name' => 'NR_2RAN2', 'type' => 'N', 'length' => 5, 'decimal' => 2],
            ['name' => 'NR_BRIG', 'type' => 'C', 'length' => 2],
            ['name' => 'NR_P1L', 'type' => 'L', 'length' => 1],
        ], [[
            'NR_12' => '-+',
            'NR_1VIH' => 42,
            'NR_2VIH' => 66,
            'NR_MINV' => 3,
            'NR_NOVIH' => 12,
            'NR_DOPPR' => '40.00',
            'NR_LINIA' => 1,
            'NR_PRAZD' => '-',
            'NR_KINT' => '0.00',
            'NR_NMN' => 1,
            'NR_NMK' => 35,
            'NR_NSM1' => '3+',
            'NR_NSM2' => '4+',
            'NR_2RAN1' => '0.00',
            'NR_2RAN2' => '0.00',
            'NR_BRIG' => '10',
            'NR_P1L' => false,
        ]]);

        $code = Artisan::call('arm:import-dbf', ['path' => $dir, '--only' => 'nrdpar']);
        $this->assertSame(0, $code);

        $planir = NaryadNorm::query()->first()->planir;
        $this->assertSame('formula', $planir['interval_mode']);
        $this->assertEquals(40, $planir['norm_extra_hours']);
        $this->assertTrue($planir['subtract_holiday_hours']);
        $this->assertSame(1, $planir['line_mode']);
        $this->assertContains('5+', $planir['night_shift_codes']);
        $this->assertEquals(5.0, $planir['early_from']);
        $this->assertEquals(8.0, $planir['early_to']);
        $this->assertEquals(10, $planir['brigadier_percent']);
    }

    public function test_assign_writes_hours_from_breakdown(): void
    {
        $dispatcher = User::factory()->dispatcher()->create();
        $driver = User::factory()->driver()->create();
        $type = ScheduleType::create([
            'name' => 'Рабочий',
            'foxpro_code' => '01',
            'routes_count' => 8,
            'people_per_route' => 2,
        ]);
        NaryadQuota::create([
            'plan_date' => '2026-03-10',
            'schedule_type_id' => $type->id,
            'required_crews' => 1,
        ]);
        ArmShiftBreakdown::create([
            'schedule_type_id' => $type->id,
            'graph_code' => '01',
            'route_code' => '25',
            'shift_code' => '1',
            'sequence' => '1',
            'hours_total' => 7.4,
            'hours_night' => 1.2,
        ]);

        $this->actingAs($dispatcher)
            ->postJson('/naryad/assign', [
                'user_id' => $driver->id,
                'plan_date' => '2026-03-10',
                'route_number' => '25 (1-с ночи)',
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $row = NaryadAssignment::where('user_id', $driver->id)->whereDate('plan_date', '2026-03-10')->first();
        $this->assertNotNull($row);
        $this->assertEquals(7.4, $row->hours_total);
        $this->assertEquals(1.2, $row->hours_night);
        $this->assertSame('1', $row->shift_code);
    }

    public function test_dispatcher_can_open_breakdowns_and_manage_holidays(): void
    {
        $dispatcher = User::factory()->dispatcher()->create();

        $this->actingAs($dispatcher)->get('/naryad/partial/breakdowns')->assertOk();
        $this->actingAs($dispatcher)->get('/naryad/partial/holidays')->assertOk();

        $this->actingAs($dispatcher)
            ->postJson('/naryad/holidays', [
                'holiday_date' => '2026-05-01',
                'name' => 'Праздник Весны и Труда',
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $holiday = ArmHoliday::first();
        $this->assertNotNull($holiday);

        $this->actingAs($dispatcher)
            ->deleteJson('/naryad/holidays/'.$holiday->id)
            ->assertOk();

        $this->assertDatabaseMissing('arm_holidays', ['id' => $holiday->id]);
    }

    public function test_driver_cannot_open_arm_catalogs(): void
    {
        $driver = User::factory()->driver()->create();

        $this->actingAs($driver)->get('/naryad/partial/breakdowns')->assertForbidden();
        $this->actingAs($driver)->get('/naryad/partial/holidays')->assertForbidden();
    }
}
