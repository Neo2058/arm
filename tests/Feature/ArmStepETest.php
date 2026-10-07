<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckDeviceBinding;
use App\Http\Middleware\CheckDynamicBarrier;
use App\Models\ArmCode;
use App\Models\ArmCodeBook;
use App\Models\ArmMonthNorm;
use App\Models\ArmPayFormula;
use App\Models\ArmPayKind;
use App\Models\ArmPremiumRate;
use App\Models\ArmRoster;
use App\Models\ArmSeniorityBand;
use App\Models\ArmSignature;
use App\Models\ArmTariff;
use App\Models\DeviationsCatalog;
use App\Models\NaryadNorm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\Support\DbfWriter;
use Tests\TestCase;

class ArmStepETest extends TestCase
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

    public function test_import_kodif_elkodif_writes_books_codes_tariffs_and_pay_kinds(): void
    {
        $dir = sys_get_temp_dir().'/arm_nsi_'.uniqid();
        mkdir($dir);

        DbfWriter::write($dir.'/KODIF.DBF', [
            ['name' => 'KODSPR', 'type' => 'C', 'length' => 2],
            ['name' => 'DBFSPR', 'type' => 'C', 'length' => 8],
            ['name' => 'NSISR', 'type' => 'C', 'length' => 1],
            ['name' => 'NAZSPR', 'type' => 'C', 'length' => 30],
            ['name' => 'DLKODEL', 'type' => 'N', 'length' => 1],
            ['name' => 'DLNAZEL', 'type' => 'N', 'length' => 2],
        ], [
            ['KODSPR' => '07', 'DBFSPR' => 'ELKODIF', 'NSISR' => '3', 'NAZSPR' => 'Виды затрат', 'DLKODEL' => 2, 'DLNAZEL' => 25],
            ['KODSPR' => '41', 'DBFSPR' => 'ELKODIF', 'NSISR' => '', 'NAZSPR' => 'Тарифные ставки', 'DLKODEL' => 8, 'DLNAZEL' => 25],
            ['KODSPR' => '50', 'DBFSPR' => 'ELKODIF', 'NSISR' => '', 'NAZSPR' => 'Виды выплат', 'DLKODEL' => 3, 'DLNAZEL' => 25],
            ['KODSPR' => '52', 'DBFSPR' => 'ELKODIF', 'NSISR' => '', 'NAZSPR' => 'Отвлечения для печати', 'DLKODEL' => 2, 'DLNAZEL' => 25],
        ]);

        DbfWriter::write($dir.'/ELKODIF.DBF', [
            ['name' => 'KODSPR', 'type' => 'C', 'length' => 2],
            ['name' => 'KODEL', 'type' => 'C', 'length' => 8],
            ['name' => 'NAZEL', 'type' => 'C', 'length' => 60],
            ['name' => 'NOM', 'type' => 'C', 'length' => 5],
        ], [
            ['KODSPR' => '07', 'KODEL' => 'Е', 'NAZEL' => 'выходной', 'NOM' => ''],
            ['KODSPR' => '41', 'KODEL' => '176.81', 'NAZEL' => 'Машинисты в 1 лицо', 'NOM' => ''],
            ['KODSPR' => '50', 'KODEL' => '004', 'NAZEL' => 'Основные часы', 'NOM' => ''],
            ['KODSPR' => '52', 'KODEL' => 'Б', 'NAZEL' => 'врем. нетрудоспос.', 'NOM' => '+'],
            ['KODSPR' => '43', 'KODEL' => '01', 'NAZEL' => 'маршрут 1', 'NOM' => ''],
            ['KODSPR' => '43', 'KODEL' => '37', 'NAZEL' => 'маршрут 37', 'NOM' => '2'],
        ]);

        DbfWriter::write($dir.'/PODPISI.DBF', [
            ['name' => 'OTBOR', 'type' => 'N', 'length' => 2],
            ['name' => 'DOLZN', 'type' => 'C', 'length' => 45],
            ['name' => 'FIO', 'type' => 'C', 'length' => 18],
        ], [
            ['OTBOR' => 1, 'DOLZN' => 'Зам. начальника', 'FIO' => 'Иванов И.И.'],
        ]);

        DbfWriter::write($dir.'/RASST.DBF', [
            ['name' => 'DAT', 'type' => 'D', 'length' => 8],
            ['name' => 'NM', 'type' => 'C', 'length' => 3],
            ['name' => 'INSTR', 'type' => 'C', 'length' => 4],
        ], [
            ['DAT' => '20260115', 'NM' => '01', 'INSTR' => '72'],
        ]);

        $code = Artisan::call('arm:import-dbf', [
            'path' => $dir,
            '--only' => 'codes,signatures,rosters',
        ]);
        $this->assertSame(0, $code);

        $this->assertDatabaseHas('arm_code_books', ['kodspr' => '07', 'nazspr' => 'Виды затрат']);
        $this->assertDatabaseHas('arm_codes', ['book_code' => '07', 'code' => 'Е', 'name' => 'выходной']);
        $this->assertTrue(ArmTariff::query()->where('code', '176.81')->exists());
        $this->assertDatabaseHas('arm_pay_kinds', ['code' => '004']);
        $this->assertDatabaseHas('arm_signatures', ['position' => 'Зам. начальника', 'full_name' => 'Иванов И.И.']);
        $this->assertDatabaseHas('arm_rosters', [
            'kind' => 'primary',
            'route_number' => '01',
            'instrument' => '72',
        ]);
        $this->assertTrue(DeviationsCatalog::query()->where('short_code', 'Б')->exists());
    }

    public function test_dispatcher_opens_nsi_and_creates_code(): void
    {
        $dispatcher = User::factory()->dispatcher()->create();
        ArmCodeBook::create([
            'kodspr' => '07',
            'nazspr' => 'Виды затрат',
            'dlkodel' => 2,
            'dlnazel' => 25,
            'nsisr' => '3',
        ]);

        $this->actingAs($dispatcher)->get('/nsi')->assertOk()->assertSee('Кодификаторы');
        $this->actingAs($dispatcher)->get('/nsi/codes')->assertOk()->assertSee('Виды затрат');
        $this->actingAs($dispatcher)->get('/nsi/codes/07')->assertOk();

        $this->actingAs($dispatcher)->post('/nsi/codes/07', [
            'code' => 'XY',
            'name' => 'тест',
            'nom' => '',
        ])->assertRedirect();

        $this->assertDatabaseHas('arm_codes', ['book_code' => '07', 'code' => 'XY', 'name' => 'тест']);
    }

    public function test_code_41_crud_keeps_tariff_table_in_sync(): void
    {
        $dispatcher = User::factory()->dispatcher()->create();
        ArmCodeBook::create([
            'kodspr' => '41',
            'nazspr' => 'Тарифные ставки',
            'dlkodel' => 8,
            'dlnazel' => 25,
        ]);

        $this->actingAs($dispatcher)->post('/nsi/norms/tariffs', [
            'code' => '176.81',
            'name' => 'Машинисты в 1 лицо',
        ])->assertRedirect();

        $this->assertDatabaseHas('arm_tariffs', ['code' => '176.81', 'name' => 'Машинисты в 1 лицо']);
        $this->assertDatabaseHas('arm_codes', ['book_code' => '41', 'code' => '176.81']);

        $tariff = ArmTariff::query()->where('code', '176.81')->first();
        $this->actingAs($dispatcher)->put('/nsi/norms/tariffs/'.$tariff->id, [
            'name' => 'МШ 1 лицо',
        ])->assertRedirect();
        $this->assertDatabaseHas('arm_tariffs', ['code' => '176.81', 'name' => 'МШ 1 лицо']);
        $this->assertDatabaseHas('arm_codes', ['book_code' => '41', 'code' => '176.81', 'name' => 'МШ 1 лицо']);
    }

    public function test_hours_premiums_seniority_and_signatures_crud(): void
    {
        $dispatcher = User::factory()->dispatcher()->create();

        $this->actingAs($dispatcher)->post('/nsi/norms/hours', [
            'year_month' => '2026-04',
            'month_hours' => 157.4,
            'day_hours' => 6.5,
        ])->assertRedirect();
        $this->assertDatabaseHas('arm_month_norms', ['year_month' => '2026-04', 'month_hours' => 157.4]);

        $this->actingAs($dispatcher)->post('/nsi/norms/premiums', [
            'position_code' => 'МШ',
            'percent' => 24,
            'name' => 'машинист',
        ])->assertRedirect();
        $this->assertTrue(ArmPremiumRate::query()->where('position_code', 'МШ')->where('is_brigadier', false)->exists());

        $this->actingAs($dispatcher)->post('/nsi/norms/seniority', [
            'years_from' => 10,
            'years_to' => 14,
            'percent' => 20,
        ])->assertRedirect();
        $this->assertDatabaseHas('arm_seniority_bands', ['years_from' => 10, 'years_to' => 14, 'percent' => 20]);

        $this->actingAs($dispatcher)->post('/nsi/signatures', [
            'sort_order' => 1,
            'position' => 'Оператор',
            'full_name' => 'Петрова',
        ])->assertRedirect();
        $this->assertDatabaseHas('arm_signatures', ['position' => 'Оператор', 'full_name' => 'Петрова']);
    }

    public function test_params_save_keeps_night_five_plus_and_stores_advance(): void
    {
        $dispatcher = User::factory()->dispatcher()->create();
        NaryadNorm::create([
            'year_hours' => 2000,
            'month_hours' => 166,
            'week_hours' => 48,
            'min_rest_hours' => 8,
            'planir' => [
                'interval_mode' => 'formula',
                'night_shift_codes' => ['3+', '4+', '5+'],
            ],
        ]);

        $this->actingAs($dispatcher)->get('/nsi/arm/params')->assertOk()->assertSee('Общие параметры');
        $this->actingAs($dispatcher)->put('/nsi/arm/params', [
            'org' => 'ТЧ-15 Печатники',
            'advance' => '50',
            'interval_mode' => 'formula',
            'line_mode' => 1,
            'norm_extra_hours' => 40,
        ])->assertRedirect();

        $planir = NaryadNorm::query()->first()->planir;
        $this->assertSame('ТЧ-15 Печатники', $planir['org']);
        $this->assertSame('50', $planir['advance']);
        $this->assertSame('formula', $planir['interval_mode']);
        $this->assertContains('5+', $planir['night_shift_codes']);

        $this->actingAs($dispatcher)->put('/nsi/arm/advance', [
            'advance' => '75',
        ])->assertRedirect();
        $this->assertSame('75', NaryadNorm::query()->first()->planir['advance']);
        $this->assertContains('5+', NaryadNorm::query()->first()->planir['night_shift_codes']);
    }

    public function test_formula_selected_toggle_and_roster_seed_from_routes(): void
    {
        $dispatcher = User::factory()->dispatcher()->create();
        $formula = ArmPayFormula::create([
            'kind' => 'machinist',
            'nom' => 1,
            'name' => 'основные',
            'formula' => 'vchas1',
            'selected' => true,
        ]);
        ArmCode::create(['book_code' => '43', 'code' => '01', 'name' => 'маршрут 1', 'nom' => '']);
        ArmCode::create(['book_code' => '43', 'code' => '37', 'name' => 'маршрут 37', 'nom' => '2']);

        $this->actingAs($dispatcher)->get('/nsi/norms/formulas')->assertOk()->assertSee('основные');
        $this->actingAs($dispatcher)->put('/nsi/norms/formulas/'.$formula->id, [])->assertRedirect();
        $this->assertFalse($formula->fresh()->selected);

        $this->actingAs($dispatcher)->post('/nsi/rosters/seed', [
            'date' => '2026-04-01',
            'kind' => 'primary',
        ])->assertRedirect();
        $this->assertTrue(ArmRoster::query()->where('route_number', '01')->where('kind', 'primary')->exists());
        $this->assertFalse(ArmRoster::query()->where('route_number', '37')->where('kind', 'primary')->exists());

        $this->actingAs($dispatcher)->post('/nsi/rosters/seed', [
            'date' => '2026-04-01',
            'kind' => 'extra',
        ])->assertRedirect();
        $this->assertTrue(ArmRoster::query()->where('route_number', '37')->where('kind', 'extra')->exists());
    }

    public function test_book_names_update(): void
    {
        $dispatcher = User::factory()->dispatcher()->create();
        $book = ArmCodeBook::create([
            'kodspr' => '07',
            'nazspr' => 'Виды затрат',
            'dlkodel' => 2,
            'dlnazel' => 25,
            'nsisr' => '3',
        ]);

        $this->actingAs($dispatcher)->put('/nsi/arm/books/'.$book->kodspr, [
            'nazspr' => 'Виды затрат рабочего времени',
            'nsisr' => '3',
            'dlkodel' => 2,
            'dlnazel' => 25,
        ])->assertRedirect();

        $this->assertDatabaseHas('arm_code_books', [
            'kodspr' => '07',
            'nazspr' => 'Виды затрат рабочего времени',
        ]);
    }
}
