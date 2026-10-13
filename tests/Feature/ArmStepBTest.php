<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckDeviceBinding;
use App\Http\Middleware\CheckDynamicBarrier;
use App\Models\ArmAccount;
use App\Models\ArmPayFormula;
use App\Models\ArmPersonnel;
use App\Models\NaryadAssignment;
use App\Models\NaryadNorm;
use App\Models\User;
use App\Services\Arm\AccountBuilder;
use App\Services\Arm\LsTotalsCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\Support\DbfWriter;
use Tests\TestCase;

class ArmStepBTest extends TestCase
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

    public function test_import_empty_tarif_keeps_chtar_for_tea_and_days(): void
    {
        $dir = sys_get_temp_dir().'/arm_b_flsm_'.uniqid();
        mkdir($dir);
        DbfWriter::write($dir.'/FLSM.DBF', [
            ['name' => 'NOM', 'type' => 'N', 'length' => 2],
            ['name' => 'NAZV', 'type' => 'C', 'length' => 20],
            ['name' => 'PROC', 'type' => 'C', 'length' => 3],
            ['name' => 'PROCR', 'type' => 'N', 'length' => 6, 'decimal' => 2],
            ['name' => 'VIDOPL', 'type' => 'C', 'length' => 3],
            ['name' => 'CHTAR', 'type' => 'N', 'length' => 10, 'decimal' => 3],
            ['name' => 'FORMULA', 'type' => 'C', 'length' => 80],
            ['name' => 'TARIF', 'type' => 'C', 'length' => 3],
            ['name' => 'N_TARIF', 'type' => 'N', 'length' => 10, 'decimal' => 3],
            ['name' => 'OTBOR', 'type' => 'C', 'length' => 1],
            ['name' => 'SHZAT', 'type' => 'C', 'length' => 7],
        ], [
            ['NOM' => 55, 'NAZV' => 'Кол-во ночных смен', 'PROC' => '', 'PROCR' => 0, 'VIDOPL' => '078', 'CHTAR' => 9.302, 'FORMULA' => 'kn', 'TARIF' => '', 'N_TARIF' => 0, 'OTBOR' => '*', 'SHZAT' => ''],
            ['NOM' => 56, 'NAZV' => 'Отработанные дни', 'PROC' => '', 'PROCR' => 0, 'VIDOPL' => 'СМН', 'CHTAR' => 1, 'FORMULA' => 'dni', 'TARIF' => '', 'N_TARIF' => 0, 'OTBOR' => '*', 'SHZAT' => ''],
            ['NOM' => 58, 'NAZV' => 'Выходные дни', 'PROC' => '', 'PROCR' => 0, 'VIDOPL' => 'ВЫХ', 'CHTAR' => 1, 'FORMULA' => 'vyh', 'TARIF' => '', 'N_TARIF' => 0, 'OTBOR' => '*', 'SHZAT' => ''],
            ['NOM' => 25, 'NAZV' => 'Премия', 'PROC' => '*', 'PROCR' => 0, 'VIDOPL' => '009', 'CHTAR' => 0, 'FORMULA' => '1', 'TARIF' => '', 'N_TARIF' => 0, 'OTBOR' => '*', 'SHZAT' => ''],
        ]);

        $code = Artisan::call('arm:import-dbf', ['path' => $dir, '--only' => 'formulas']);
        $this->assertSame(0, $code);
        $this->assertEqualsWithDelta(9.302, (float) ArmPayFormula::query()->where('pay_code', '078')->value('tariff'), 0.0005);
        $this->assertEqualsWithDelta(1.0, (float) ArmPayFormula::query()->where('pay_code', 'СМН')->value('tariff'), 0.0005);
        $this->assertEqualsWithDelta(0.0, (float) ArmPayFormula::query()->where('pay_code', '009')->value('tariff'), 0.0005);
    }

    public function test_lsbuh_lines_use_chtar_and_skip_premium_without_tariff(): void
    {
        $driver = User::factory()->driver()->create();
        ArmPersonnel::create([
            'user_id' => $driver->id,
            'tab_number' => '0321',
            'full_name' => 'ИВАНОВ',
            'position_code' => 'МШ',
        ]);
        NaryadAssignment::create([
            'user_id' => $driver->id,
            'plan_date' => '2026-04-03',
            'route_number' => '25',
            'shift_code' => '3+',
            'hours_line' => 8,
            'hours_night' => 2,
        ]);
        NaryadAssignment::create([
            'user_id' => $driver->id,
            'plan_date' => '2026-04-05',
            'route_number' => 'Е',
            'shift_code' => '',
            'work_code' => 'Е',
        ]);
        ArmPayFormula::create([
            'kind' => 'machinist',
            'nom' => 1,
            'name' => 'Основные часы',
            'percent' => 100,
            'percent_text' => '100',
            'pay_code' => '004',
            'tariff_code' => 'tm1',
            'tariff' => 176.81,
            'formula' => 'vchas1',
            'selected' => true,
        ]);
        ArmPayFormula::create([
            'kind' => 'machinist',
            'nom' => 55,
            'name' => 'Кол-во ночных смен',
            'percent' => 0,
            'pay_code' => '078',
            'tariff' => 9.302,
            'formula' => 'kn',
            'selected' => true,
        ]);
        ArmPayFormula::create([
            'kind' => 'machinist',
            'nom' => 56,
            'name' => 'Отработанные дни',
            'percent' => 0,
            'pay_code' => 'СМН',
            'tariff' => 1,
            'formula' => 'dni',
            'selected' => true,
        ]);
        ArmPayFormula::create([
            'kind' => 'machinist',
            'nom' => 58,
            'name' => 'Выходные дни',
            'percent' => 0,
            'pay_code' => 'ВЫХ',
            'tariff' => 1,
            'formula' => 'vyh',
            'selected' => true,
        ]);
        ArmPayFormula::create([
            'kind' => 'machinist',
            'nom' => 25,
            'name' => 'Премия',
            'percent' => 0,
            'percent_source' => 'premm',
            'percent_text' => '*',
            'pay_code' => '009',
            'tariff' => 0,
            'formula' => '1',
            'selected' => true,
        ]);

        app(AccountBuilder::class)->buildMonth('2026-04');
        $account = ArmAccount::query()->with('lines')->first();
        $this->assertNotNull($account);

        $tea = $account->lines->firstWhere('pay_code', '078');
        $this->assertNotNull($tea);
        $this->assertEqualsWithDelta(9.302, (float) $tea->tariff, 0.0005);
        $this->assertEqualsWithDelta(100.0, (float) $tea->percent, 0.0005);
        $this->assertEqualsWithDelta(1.0, (float) $tea->hours, 0.0005);

        $days = $account->lines->firstWhere('pay_code', 'СМН');
        $this->assertNotNull($days);
        $this->assertEqualsWithDelta(1.0, (float) $days->tariff, 0.0005);
        $this->assertEqualsWithDelta(100.0, (float) $days->percent, 0.0005);
        $this->assertEqualsWithDelta(1.0, (float) $days->hours, 0.0005);

        $rest = $account->lines->firstWhere('pay_code', 'ВЫХ');
        $this->assertNotNull($rest);
        $this->assertEqualsWithDelta(1.0, (float) $rest->tariff, 0.0005);
        $this->assertEqualsWithDelta(1.0, (float) $rest->hours, 0.0005);

        $this->assertNull($account->lines->firstWhere('pay_code', '009'));
    }

    public function test_kn_counts_nsm1_nsm2_and_skips_five_plus(): void
    {
        $user = User::factory()->driver()->create();
        $personnel = ArmPersonnel::create([
            'user_id' => $user->id,
            'tab_number' => '2575',
            'full_name' => 'ПМ',
            'position_code' => 'П/М',
        ]);
        $rows = collect([
            new NaryadAssignment([
                'user_id' => $user->id,
                'plan_date' => '2026-04-01',
                'route_number' => '12',
                'shift_code' => '3+',
                'hours_line' => 8,
            ]),
            new NaryadAssignment([
                'user_id' => $user->id,
                'plan_date' => '2026-04-02',
                'route_number' => '12',
                'shift_code' => '4+',
                'hours_line' => 8,
            ]),
            new NaryadAssignment([
                'user_id' => $user->id,
                'plan_date' => '2026-04-03',
                'route_number' => '12',
                'shift_code' => '5+',
                'hours_line' => 8,
            ]),
        ]);

        $t = (new LsTotalsCalculator)->calculate($personnel, '2026-04', $rows);
        $this->assertEquals(2.0, $t['kn']);
        $this->assertEquals(3.0, $t['dni']);
        $this->assertContains('5+', NaryadNorm::query()->first()->planir['night_shift_codes'] ?? ['3+', '4+', '5+']);
    }

    public function test_nrdpar_import_keeps_five_plus_and_sets_night_count_codes(): void
    {
        $dir = sys_get_temp_dir().'/arm_b_nrd_'.uniqid();
        mkdir($dir);
        DbfWriter::write($dir.'/NRDPAR.DBF', [
            ['name' => 'NR_12', 'type' => 'C', 'length' => 2],
            ['name' => 'NR_NSM1', 'type' => 'C', 'length' => 2],
            ['name' => 'NR_NSM2', 'type' => 'C', 'length' => 2],
            ['name' => 'NR_LINIA', 'type' => 'N', 'length' => 1],
            ['name' => 'NR_PRAZD', 'type' => 'C', 'length' => 1],
            ['name' => 'NR_2RAN1', 'type' => 'N', 'length' => 5, 'decimal' => 2],
            ['name' => 'NR_2RAN2', 'type' => 'N', 'length' => 5, 'decimal' => 2],
        ], [[
            'NR_12' => '-+',
            'NR_NSM1' => '3+',
            'NR_NSM2' => '4+',
            'NR_LINIA' => 1,
            'NR_PRAZD' => '-',
            'NR_2RAN1' => '0.00',
            'NR_2RAN2' => '0.00',
        ]]);

        $this->assertSame(0, Artisan::call('arm:import-dbf', ['path' => $dir, '--only' => 'nrdpar']));
        $planir = NaryadNorm::query()->first()->planir;
        $this->assertContains('5+', $planir['night_shift_codes']);
        $this->assertSame(['3+', '4+'], $planir['night_count_codes']);
        $this->assertNotContains('5+', $planir['night_count_codes']);
    }
}
