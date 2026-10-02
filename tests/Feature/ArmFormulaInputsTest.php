<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckDeviceBinding;
use App\Http\Middleware\CheckDynamicBarrier;
use App\Models\ArmExtraPay;
use App\Models\ArmHoliday;
use App\Models\ArmMonthNorm;
use App\Models\ArmMonthPremium;
use App\Models\ArmPayFormula;
use App\Models\ArmPersonnel;
use App\Models\ArmPremiumRate;
use App\Models\ArmSeniorityBand;
use App\Models\ArmTariff;
use App\Models\NaryadAssignment;
use App\Models\NaryadNorm;
use App\Models\User;
use App\Services\Arm\AccountBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\Support\DbfWriter;
use Tests\TestCase;

class ArmFormulaInputsTest extends TestCase
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

    public function test_import_loads_nrchas_prem_visl_tehuch_spprem_and_tariffs(): void
    {
        $dir = sys_get_temp_dir().'/arm_dbf_a_'.uniqid();
        mkdir($dir);

        DbfWriter::write($dir.'/NRCHAS.DBF', [
            ['name' => 'MESGOD', 'type' => 'C', 'length' => 7],
            ['name' => 'MESNR', 'type' => 'N', 'length' => 5, 'decimal' => 1],
            ['name' => 'DNNR', 'type' => 'N', 'length' => 5, 'decimal' => 3],
        ], [
            ['MESGOD' => '04.2026', 'MESNR' => 157.4, 'DNNR' => 6.054],
        ]);

        DbfWriter::write($dir.'/PREM.DBF', [
            ['name' => 'DOLZN', 'type' => 'C', 'length' => 5],
            ['name' => 'PBR', 'type' => 'C', 'length' => 1],
            ['name' => 'PROC', 'type' => 'N', 'length' => 6, 'decimal' => 2],
            ['name' => 'NAZV', 'type' => 'C', 'length' => 20],
        ], [
            ['DOLZN' => 'МШ', 'PBR' => '-', 'PROC' => 24, 'NAZV' => 'машинист'],
            ['DOLZN' => 'П/М', 'PBR' => '-', 'PROC' => 45, 'NAZV' => 'помощник'],
        ]);

        DbfWriter::write($dir.'/VISL.DBF', [
            ['name' => 'OT', 'type' => 'N', 'length' => 2],
            ['name' => 'DO', 'type' => 'N', 'length' => 2],
            ['name' => 'PROC', 'type' => 'N', 'length' => 2],
        ], [
            ['OT' => 10, 'DO' => 14, 'PROC' => 20],
            ['OT' => 0, 'DO' => 0, 'PROC' => 0],
        ]);

        DbfWriter::write($dir.'/TEHUCH.DBF', [
            ['name' => 'GODMES', 'type' => 'C', 'length' => 6],
            ['name' => 'TABNOM', 'type' => 'C', 'length' => 4],
            ['name' => 'FIO', 'type' => 'C', 'length' => 18],
            ['name' => 'DOLZN', 'type' => 'C', 'length' => 5],
            ['name' => 'CHAS', 'type' => 'N', 'length' => 6, 'decimal' => 2],
            ['name' => 'DAT', 'type' => 'D', 'length' => 8],
            ['name' => 'AVAR', 'type' => 'N', 'length' => 6, 'decimal' => 2],
            ['name' => 'DATAV', 'type' => 'D', 'length' => 8],
            ['name' => 'MEDK', 'type' => 'N', 'length' => 6, 'decimal' => 2],
            ['name' => 'DTMEDK', 'type' => 'D', 'length' => 8],
            ['name' => 'NVIH', 'type' => 'N', 'length' => 1],
            ['name' => 'NVIH_CH', 'type' => 'N', 'length' => 6, 'decimal' => 2],
        ], [
            [
                'GODMES' => '202604', 'TABNOM' => '0321', 'FIO' => 'ИВАНОВ', 'DOLZN' => 'МШ',
                'CHAS' => 2, 'DAT' => '', 'AVAR' => 0, 'DATAV' => '', 'MEDK' => 4, 'DTMEDK' => '',
                'NVIH' => 1, 'NVIH_CH' => 0,
            ],
        ]);

        DbfWriter::write($dir.'/SPPREM.DBF', [
            ['name' => 'FIO', 'type' => 'C', 'length' => 18],
            ['name' => 'TABNOM', 'type' => 'C', 'length' => 4],
            ['name' => 'DOLZN', 'type' => 'C', 'length' => 5],
            ['name' => 'PREMP', 'type' => 'N', 'length' => 6, 'decimal' => 2],
            ['name' => 'PREMF', 'type' => 'N', 'length' => 6, 'decimal' => 2],
            ['name' => 'PRIM', 'type' => 'C', 'length' => 25],
            ['name' => 'GODMES', 'type' => 'C', 'length' => 6],
            ['name' => 'KTU', 'type' => 'N', 'length' => 4, 'decimal' => 2],
        ], [
            ['FIO' => 'ИВАНОВ', 'TABNOM' => '0321', 'DOLZN' => 'МШ', 'PREMP' => 24, 'PREMF' => 24, 'PRIM' => '', 'GODMES' => '202604', 'KTU' => 1],
        ]);

        DbfWriter::write($dir.'/ELKODIF.DBF', [
            ['name' => 'KODSPR', 'type' => 'C', 'length' => 2],
            ['name' => 'KODEL', 'type' => 'C', 'length' => 8],
            ['name' => 'NAZEL', 'type' => 'C', 'length' => 60],
            ['name' => 'NOM', 'type' => 'C', 'length' => 5],
        ], [
            ['KODSPR' => '41', 'KODEL' => '176.81', 'NAZEL' => 'Машинисты в 1 лицо', 'NOM' => ''],
            ['KODSPR' => '50', 'KODEL' => '004', 'NAZEL' => 'Основные часы', 'NOM' => ''],
        ]);

        $code = Artisan::call('arm:import-dbf', [
            'path' => $dir,
            '--only' => 'nrchas,prem,visl,extras,spprem,tariffs',
        ]);
        $this->assertSame(0, $code);

        $this->assertDatabaseHas('arm_month_norms', ['year_month' => '2026-04', 'month_hours' => 157.4]);
        $this->assertTrue(ArmPremiumRate::query()->where('position_code', 'МШ')->where('is_brigadier', false)->exists());
        $this->assertDatabaseHas('arm_seniority_bands', ['years_from' => 10, 'years_to' => 14, 'percent' => 20]);
        $this->assertDatabaseHas('arm_extra_pays', ['year_month' => '2026-04', 'tab_number' => '0321', 'hours_tech' => 2]);
        $this->assertDatabaseHas('arm_month_premiums', ['year_month' => '2026-04', 'tab_number' => '0321', 'percent_fact' => 24]);
        $this->assertTrue(ArmTariff::query()->where('code', '176.81')->exists());
        $this->assertDatabaseHas('arm_pay_kinds', ['code' => '004']);
    }

    public function test_account_totals_fill_dob_nvih_teh_prem_visl(): void
    {
        $driver = User::factory()->driver()->create(['name' => 'Иванов']);
        ArmPersonnel::create([
            'user_id' => $driver->id,
            'tab_number' => '0321',
            'full_name' => 'ИВАНОВ',
            'position_code' => 'МШ',
            'class_code' => '1',
            'seniority_on' => '2016-04-01',
        ]);
        ArmMonthNorm::create(['year_month' => '2026-04', 'month_hours' => 80, 'day_hours' => 6]);
        ArmHoliday::create(['holiday_date' => '2026-04-01', 'name' => 'тест']);
        ArmSeniorityBand::create(['years_from' => 10, 'years_to' => 14, 'percent' => 20]);
        ArmExtraPay::create([
            'year_month' => '2026-04',
            'user_id' => $driver->id,
            'tab_number' => '0321',
            'position_code' => 'МШ',
            'hours_tech' => 2,
            'hours_med' => 4,
            'extra_days_off' => 1,
        ]);
        ArmMonthPremium::create([
            'year_month' => '2026-04',
            'user_id' => $driver->id,
            'tab_number' => '0321',
            'position_code' => 'МШ',
            'percent_plan' => 24,
            'percent_fact' => 24,
        ]);
        NaryadAssignment::create([
            'user_id' => $driver->id,
            'plan_date' => '2026-04-03',
            'route_number' => '25',
            'shift_code' => '2',
            'hours_line' => 50,
            'hours_holiday' => 10,
        ]);
        ArmPayFormula::create([
            'kind' => 'machinist',
            'nom' => 1,
            'name' => 'Основные часы 1 лицо',
            'percent' => 100,
            'pay_code' => '004',
            'formula' => 'Vchas1+dob1',
            'selected' => true,
        ]);
        ArmPayFormula::create([
            'kind' => 'machinist',
            'nom' => 25,
            'name' => 'Премия',
            'percent' => 0,
            'percent_source' => 'premm',
            'pay_code' => '009',
            'formula' => '1',
            'selected' => true,
        ]);

        $account = app(AccountBuilder::class)->buildForUser($driver->id, '2026-04');
        $t = $account->totals;

        $this->assertEquals(50.0, $t['vchas1']);
        $this->assertEquals(10.0, $t['prazd1']);
        $this->assertEquals(6.0, $t['nvih']);
        $this->assertEquals(2.0, $t['teh']);
        $this->assertEquals(4.0, $t['medk']);
        $this->assertEquals(24.0, $t['premm']);
        $this->assertEquals(24.0, $t['prem']);
        $this->assertEquals(20.0, $t['vislp']);
        $this->assertGreaterThan(0, $t['visl']);
        $this->assertEquals(10.0, $t['dob1']);
        $this->assertEquals(6.0, $t['dobnv']);
        $this->assertEquals(50.0, $t['kl1']);
        $this->assertEquals(80.0, $t['tek_nr']);

        $this->assertTrue($account->lines->contains(fn ($l) => $l->pay_code === '004' && abs((float) $l->hours - 60.0) < 0.001));
        $premLine = $account->lines->firstWhere('pay_code', '009');
        $this->assertNotNull($premLine);
        $this->assertEquals(24.0, (float) $premLine->percent);
        $this->assertEquals(1.0, (float) $premLine->hours);
    }

    public function test_operator_saves_extras_and_driver_is_forbidden(): void
    {
        $operator = User::factory()->operator()->create();
        $driver = User::factory()->driver()->create();
        ArmPersonnel::create([
            'user_id' => $driver->id,
            'tab_number' => '0444',
            'full_name' => 'ТЕСТ',
            'position_code' => 'МШ',
        ]);

        $this->actingAs($operator)->get('/uchet/extras?month=2026-04')->assertOk();
        $this->actingAs($driver)->get('/uchet/extras')->assertForbidden();

        $this->actingAs($operator)->putJson('/uchet/extras/'.$driver->id.'?month=2026-04', [
            'hours_tech' => 3,
            'hours_med' => 1.5,
            'extra_days_off' => 2,
            'extra_hours_off' => 0,
        ])->assertOk()->assertJson(['success' => true]);

        $this->assertDatabaseHas('arm_extra_pays', [
            'year_month' => '2026-04',
            'tab_number' => '0444',
            'hours_tech' => 3,
        ]);

        $this->actingAs($operator)->putJson('/uchet/premiums/'.$driver->id.'?month=2026-04', [
            'percent_fact' => 24,
            'percent_plan' => 24,
        ])->assertOk();
        $this->assertDatabaseHas('arm_month_premiums', [
            'year_month' => '2026-04',
            'tab_number' => '0444',
            'percent_fact' => 24,
        ]);
    }

    public function test_closed_month_blocks_extra_pay_edit(): void
    {
        $operator = User::factory()->operator()->create();
        $driver = User::factory()->driver()->create();
        ArmPersonnel::create([
            'user_id' => $driver->id,
            'tab_number' => '0555',
            'full_name' => 'ТЕСТ',
            'position_code' => 'МШ',
        ]);
        \App\Models\ArmPeriod::create(['year_month' => '2026-04', 'status' => 'closed']);

        $this->actingAs($operator)->putJson('/uchet/extras/'.$driver->id.'?month=2026-04', [
            'hours_tech' => 2,
        ])->assertStatus(422);
    }
}
