<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckDeviceBinding;
use App\Http\Middleware\CheckDynamicBarrier;
use App\Models\ArmAccount;
use App\Models\ArmAccountLine;
use App\Models\ArmCode;
use App\Models\ArmPersonnel;
use App\Models\NaryadNorm;
use App\Models\User;
use App\Services\Arm\DbfReader;
use App\Services\Arm\LsbuhExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ArmStepGTest extends TestCase
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

    public function test_dbf_layout_matches_pechatniki_lsbuh(): void
    {
        $layout = app(LsbuhExportService::class)->fieldLayout();
        $this->assertSame([
            ['name' => 'GODMES', 'type' => 'C', 'length' => 6, 'decimal' => 0],
            ['name' => 'TABN', 'type' => 'C', 'length' => 4, 'decimal' => 0],
            ['name' => 'VOPL', 'type' => 'C', 'length' => 3, 'decimal' => 0],
            ['name' => 'TARST', 'type' => 'N', 'length' => 10, 'decimal' => 3],
            ['name' => 'PROCNT', 'type' => 'N', 'length' => 6, 'decimal' => 2],
            ['name' => 'VROTR', 'type' => 'N', 'length' => 6, 'decimal' => 2],
            ['name' => 'ZAKAZ', 'type' => 'C', 'length' => 7, 'decimal' => 0],
            ['name' => 'PROF', 'type' => 'C', 'length' => 3, 'decimal' => 0],
            ['name' => 'OTBOR', 'type' => 'L', 'length' => 1, 'decimal' => 0],
            ['name' => 'NOMLS', 'type' => 'C', 'length' => 1, 'decimal' => 0],
            ['name' => 'DDE', 'type' => 'C', 'length' => 1, 'decimal' => 0],
            ['name' => 'OSN_PROF', 'type' => 'C', 'length' => 3, 'decimal' => 0],
            ['name' => 'TABN_OLD', 'type' => 'C', 'length' => 4, 'decimal' => 0],
            ['name' => 'DEPO', 'type' => 'C', 'length' => 4, 'decimal' => 0],
            ['name' => 'DEPOKOM', 'type' => 'C', 'length' => 4, 'decimal' => 0],
        ], $layout);
    }

    public function test_operator_downloads_lsbuh_dbf_and_csv(): void
    {
        $operator = User::factory()->operator()->create();
        $this->seedLine('0321', [
            'position_code' => 'МШ',
            'depo_code' => 'ТЧ15',
        ], [
            'pay_code' => '004',
            'tariff' => 176.81,
            'percent' => 100,
            'hours' => 112,
            'cost_code' => '2000058',
            'profession' => '036',
            'ls_number' => '0',
        ]);
        ArmCode::create(['book_code' => '05', 'code' => 'МШ', 'name' => 'машинист', 'nom' => '036']);

        $csv = $this->actingAs($operator)->get('/uchet/lsbuh.csv?month=2026-04');
        $csv->assertOk();
        $body = $csv->streamedContent();
        $this->assertStringContainsString('GODMES', $body);
        $this->assertStringContainsString('0321', $body);
        $this->assertStringContainsString('004', $body);
        $this->assertStringContainsString('202604', $body);

        $response = $this->actingAs($operator)->get('/uchet/lsbuh.dbf?month=2026-04');
        $response->assertOk();
        $response->assertDownload('LSBUH.DBF');

        $tmp = sys_get_temp_dir().'/lsbuh_g_'.uniqid().'.dbf';
        file_put_contents($tmp, $response->getFile()->getContent());
        $reader = new DbfReader($tmp);
        $this->assertSame(
            array_column(app(LsbuhExportService::class)->fieldLayout(), 'name'),
            array_column($reader->fields(), 'name')
        );
        $row = $reader->all()[0];
        $this->assertSame('202604', $row['GODMES']);
        $this->assertSame('0321', $row['TABN']);
        $this->assertSame('0321', $row['TABN_OLD']);
        $this->assertSame('004', $row['VOPL']);
        $this->assertEqualsWithDelta(176.81, (float) $row['TARST'], 0.0005);
        $this->assertEqualsWithDelta(100.0, (float) $row['PROCNT'], 0.0005);
        $this->assertEqualsWithDelta(112.0, (float) $row['VROTR'], 0.0005);
        $this->assertSame('2000058', $row['ZAKAZ']);
        $this->assertSame('036', $row['PROF']);
        $this->assertSame('036', $row['OSN_PROF']);
        $this->assertSame('0', $row['NOMLS']);
        $this->assertSame('', $row['DEPO']);
        $this->assertSame('', $row['DDE']);
        $this->assertFalse((bool) $row['OTBOR']);
        @unlink($tmp);
    }

    public function test_main_tab_and_foreign_depo_rewrite_as_podg_lsh(): void
    {
        $this->seedLine('4401', [
            'position_code' => 'П/М',
            'main_tab_number' => '9901',
            'depo_code' => 'ТЧ02',
        ], [
            'pay_code' => '025',
            'tariff' => 9.302,
            'percent' => 30,
            'hours' => 8.5,
            'cost_code' => '2000001',
            'profession' => '039',
            'ls_number' => '0',
        ]);
        ArmCode::create(['book_code' => '05', 'code' => 'П/М', 'name' => 'помощник', 'nom' => '039']);

        $rows = app(LsbuhExportService::class)->rows('2026-04');
        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertSame('9901', $row['tabn']);
        $this->assertSame('4401', $row['tabn_old']);
        $this->assertSame('ТЧ02', $row['depo']);
        $this->assertSame('5', $row['nomls']);
        $this->assertSame('039', $row['osn_prof']);
        $this->assertSame('', $row['dde']);
    }

    public function test_own_depo_does_not_fill_depo_or_bump_nomls(): void
    {
        $this->seedLine('0321', [
            'position_code' => 'МШ',
            'depo_code' => 'ТЧ15',
        ], [
            'pay_code' => '004',
            'tariff' => 176.81,
            'percent' => 100,
            'hours' => 8,
            'cost_code' => '2000058',
            'profession' => '036',
            'ls_number' => '1',
        ]);

        $row = app(LsbuhExportService::class)->rows('2026-04')[0];
        $this->assertSame('0321', $row['tabn']);
        $this->assertSame('', $row['depo']);
        $this->assertSame('1', $row['nomls']);
    }

    public function test_dde_flag_from_planir_sets_dde_on_accounting_copy(): void
    {
        NaryadNorm::query()->first()->update([
            'planir' => ['own_depo' => 'ТЧ15', 'dde' => '+'],
        ]);
        $this->seedLine('0321', ['depo_code' => 'ТЧ15'], [
            'pay_code' => '004',
            'tariff' => 1,
            'percent' => 100,
            'hours' => 1,
            'ls_number' => '0',
        ]);

        $this->assertSame('1', app(LsbuhExportService::class)->rows('2026-04')[0]['dde']);
    }

    public function test_driver_cannot_download_lsbuh_dbf(): void
    {
        $driver = User::factory()->driver()->create();
        $this->actingAs($driver)->get('/uchet/lsbuh.dbf?month=2026-04')->assertForbidden();
        $this->actingAs($driver)->get('/uchet/lsbuh.csv?month=2026-04')->assertForbidden();
    }

    public function test_artisan_writes_lsbuh_dbf(): void
    {
        $this->seedLine('0321', [], [
            'pay_code' => '004',
            'tariff' => 176.81,
            'percent' => 100,
            'hours' => 10,
            'ls_number' => '0',
        ]);
        $path = sys_get_temp_dir().'/LSBUH_'.uniqid().'.DBF';
        $code = Artisan::call('arm:export-lsbuh', ['month' => '2026-04', 'path' => $path]);
        $this->assertSame(0, $code);
        $this->assertFileExists($path);
        $row = (new DbfReader($path))->all()[0];
        $this->assertSame('0321', $row['TABN']);
        $this->assertSame('004', $row['VOPL']);
        @unlink($path);
    }

    /**
     * @param  array<string, mixed>  $personnel
     * @param  array<string, mixed>  $line
     */
    private function seedLine(string $tab, array $personnel, array $line): ArmAccount
    {
        $driver = User::factory()->driver()->create(['name' => 'ТАБ '.$tab]);
        ArmPersonnel::create(array_merge([
            'user_id' => $driver->id,
            'tab_number' => $tab,
            'full_name' => 'ТАБ '.$tab,
            'position_code' => 'МШ',
        ], $personnel));
        $account = ArmAccount::create([
            'user_id' => $driver->id,
            'year_month' => '2026-04',
            'kind' => 'primary',
            'ls_number' => $line['ls_number'] ?? '0',
            'position_code' => $personnel['position_code'] ?? 'МШ',
            'formula_kind' => 'machinist',
            'status' => 'draft',
        ]);
        ArmAccountLine::create(array_merge([
            'arm_account_id' => $account->id,
            'sort' => 1,
            'name' => 'основные',
            'pay_code' => '004',
            'tariff' => 0,
            'percent' => 100,
            'hours' => 1,
            'profession' => '036',
            'ls_number' => '0',
        ], $line));

        return $account;
    }
}
