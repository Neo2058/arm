<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckDeviceBinding;
use App\Http\Middleware\CheckDynamicBarrier;
use App\Models\ArmAccount;
use App\Models\ArmPersonnel;
use App\Models\NaryadAssignment;
use App\Models\NaryadNorm;
use App\Models\User;
use App\Services\Arm\GoldenMonthComparer;
use App\Services\ClickHouseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\Support\DbfWriter;
use Tests\TestCase;

class ArmGoldenMonthTest extends TestCase
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

    public function test_import_naryad_writes_hours_from_live_and_monthly_files(): void
    {
        $dir = sys_get_temp_dir().'/arm_nr_'.uniqid();
        mkdir($dir);
        $fields = $this->naryadFields();
        DbfWriter::write($dir.'/NARJAD.DBF', $fields, [
            [
                'DAT' => '20181105', 'TABNOM' => '0428', 'WORK' => '08', 'SMENA' => '2',
                'CHAS' => 8.4, 'LIN1' => 8.4, 'NOCH1' => 0, 'VECH1' => 1.2, 'RAZR1' => 0.5,
                'PRAZD1' => 0, 'REZM2L' => '',
            ],
        ]);
        DbfWriter::write($dir.'/NR1118.DBF', $fields, [
            [
                'DAT' => '20181112', 'TABNOM' => '0428', 'WORK' => '35', 'SMENA' => '4+',
                'CHAS' => 5.4, 'LIN1' => 5.4, 'NOCH1' => 5.4, 'VECH1' => 2, 'RAZR1' => 0,
                'PRAZD1' => 0, 'REZM2L' => '+',
            ],
        ]);

        $user = User::factory()->driver()->create();
        ArmPersonnel::create(['user_id' => $user->id, 'tab_number' => '0428', 'full_name' => 'ЛАВРЕНТЬЕВ']);

        Artisan::call('arm:import-dbf', [
            'path' => $dir,
            '--only' => 'naryad',
            '--month' => '2018-11',
            '--tabs' => '0428',
        ]);

        $rows = NaryadAssignment::query()->orderBy('plan_date')->get();
        $this->assertCount(2, $rows);
        $this->assertSame('08', $rows[0]->work_code);
        $this->assertEquals(8.4, (float) $rows[0]->hours_line);
        $this->assertSame('4+', $rows[1]->shift_code);
        $this->assertTrue((bool) $rows[1]->two_person);
        $this->assertEquals(5.4, (float) $rows[1]->hours_night);
    }

    public function test_golden_month_matches_naryad_hours_and_lsbuh_line(): void
    {
        ClickHouseService::fake();
        $dir = sys_get_temp_dir().'/arm_gold_'.uniqid();
        mkdir($dir);
        DbfWriter::write($dir.'/NARJAD.DBF', $this->naryadFields(), [
            [
                'DAT' => '20181105', 'TABNOM' => '0428', 'WORK' => '08', 'SMENA' => '2',
                'CHAS' => 8, 'LIN1' => 8, 'NOCH1' => 0, 'VECH1' => 1, 'RAZR1' => 0.5,
                'PRAZD1' => 0, 'REZM2L' => '',
            ],
            [
                'DAT' => '20181112', 'TABNOM' => '0428', 'WORK' => '35', 'SMENA' => '4+',
                'CHAS' => 8, 'LIN1' => 8, 'NOCH1' => 4, 'VECH1' => 2, 'RAZR1' => 0.5,
                'PRAZD1' => 0, 'REZM2L' => '',
            ],
            [
                'DAT' => '20181102', 'TABNOM' => '0428', 'WORK' => 'Е', 'SMENA' => '',
                'CHAS' => 0, 'LIN1' => 0, 'NOCH1' => 0, 'VECH1' => 0, 'RAZR1' => 0,
                'PRAZD1' => 0, 'REZM2L' => '',
            ],
        ]);
        DbfWriter::write($dir.'/LSH.DBF', $this->lshFields(), [
            [
                'TABNOM' => '0428', 'GODMES' => '201811', 'DOLZN' => 'МШ',
                'VCHAS1' => 16, 'VC1' => 16, 'NOCH1' => 4, 'VECH1' => 3, 'RAZR1' => 1,
                'PRAZD1' => 0, 'DNI' => 2, 'VYH' => 1, 'KN' => 1,
                'TEK_NR' => 16, 'PER1' => 0, 'VISL' => 0, 'PREMM' => 0, 'BRIG' => 0,
                'NVIH' => 0, 'DOB1' => 0, 'TEH' => 0, 'MEDK' => 0, 'KL1' => 16,
            ],
        ]);
        DbfWriter::write($dir.'/LSBUH.DBF', $this->lsbuhFields(), [
            [
                'GODMES' => '201811', 'TABN' => '0428', 'VOPL' => '004',
                'TARST' => 176.81, 'PROCNT' => 100, 'VROTR' => 16, 'ZAKAZ' => '2000058',
                'PROF' => '036', 'NOMLS' => '0',
            ],
            [
                'GODMES' => '201811', 'TABN' => '0428', 'VOPL' => '009',
                'TARST' => 176.81, 'PROCNT' => 24, 'VROTR' => 0, 'ZAKAZ' => '2000058',
                'PROF' => '036', 'NOMLS' => '0',
            ],
        ]);
        DbfWriter::write($dir.'/FLSM.DBF', [
            ['name' => 'NOM', 'type' => 'N', 'length' => 2],
            ['name' => 'NAZV', 'type' => 'C', 'length' => 30],
            ['name' => 'PROCR', 'type' => 'N', 'length' => 6, 'decimal' => 2],
            ['name' => 'VIDOPL', 'type' => 'C', 'length' => 3],
            ['name' => 'TARIF', 'type' => 'C', 'length' => 8],
            ['name' => 'N_TARIF', 'type' => 'N', 'length' => 10, 'decimal' => 3],
            ['name' => 'SHZAT', 'type' => 'C', 'length' => 7],
            ['name' => 'FORMULA', 'type' => 'C', 'length' => 80],
        ], [
            [
                'NOM' => 1, 'NAZV' => 'Основные часы 1 лицо', 'PROCR' => 100,
                'VIDOPL' => '004', 'TARIF' => '', 'N_TARIF' => 176.81, 'SHZAT' => '2000058',
                'FORMULA' => 'Vchas1',
            ],
        ]);
        DbfWriter::write($dir.'/TEHUCH.DBF', [
            ['name' => 'GODMES', 'type' => 'C', 'length' => 6],
            ['name' => 'TABNOM', 'type' => 'C', 'length' => 4],
            ['name' => 'FIO', 'type' => 'C', 'length' => 18],
            ['name' => 'DOLZN', 'type' => 'C', 'length' => 5],
        ], [
            ['GODMES' => '201811', 'TABNOM' => '0428', 'FIO' => 'ЛАВРЕНТЬЕВ К.Ф+', 'DOLZN' => 'МШ'],
        ]);

        $code = Artisan::call('arm:golden-month', [
            'month' => '2018-11',
            'path' => $dir,
            '--tabs' => '0428',
        ]);
        $this->assertSame(0, $code, Artisan::output());
        $golden = array_values(array_filter(
            ClickHouseService::recorded(),
            fn (array $row) => $row['action_type'] === 'arm.golden_month'
        ));
        $this->assertNotEmpty($golden);
        $this->assertSame(0, $golden[0]['user_id']);
        $this->assertStringContainsString('"month":"2018-11"', $golden[0]['details']);
        $this->assertStringContainsString('"discrepancies":0', $golden[0]['details']);

        $this->assertSame(3, NaryadAssignment::query()->count());
        $account = ArmAccount::query()->first();
        $this->assertNotNull($account);
        $this->assertEquals(16.0, (float) ($account->totals['vchas1'] ?? 0));
        $this->assertEquals(4.0, (float) ($account->totals['noch1'] ?? 0));
        $this->assertEquals(1.0, (float) ($account->totals['vyh'] ?? 0));
    }

    public function test_pechatniki_dump_november_2018_line_hours_match_lsh(): void
    {
        $dir = $_SERVER['HOME'].'/Downloads/ARMNRD/Datanrd';
        if (! is_file($dir.'/NARJAD.DBF') || ! is_file($dir.'/LSH.DBF')) {
            $this->markTestSkipped('Нет дампа ARMNRD/Datanrd');
        }

        $tabs = ['0321', '0428', '2575'];
        Artisan::call('arm:golden-month', [
            'month' => '2018-11',
            'path' => $dir,
            '--tabs' => implode(',', $tabs),
        ]);

        $report = (new GoldenMonthComparer)->compare($dir, '2018-11', $tabs);
        foreach ($tabs as $tab) {
            $this->assertNotEmpty($report['fox_lsh'][$tab] ?? [], 'нет LSH '.$tab);
            foreach (['vchas1', 'vc1', 'dni', 'vyh'] as $field) {
                $this->assertEqualsWithDelta(
                    (float) $report['fox_lsh'][$tab][$field],
                    (float) $report['fox_naryad'][$tab][$field],
                    0.015,
                    "{$tab} NARJAD.{$field} vs LSH"
                );
                $this->assertEqualsWithDelta(
                    (float) $report['fox_naryad'][$tab][$field],
                    (float) $report['clone_hours'][$tab][$field],
                    0.015,
                    "{$tab} NARJAD.{$field} vs клон"
                );
            }
            $this->assertEqualsWithDelta(
                (float) $report['fox_lsh'][$tab]['vchas1'],
                (float) ($report['clone_lsh'][$tab]['vchas1'] ?? 0),
                0.015,
                "{$tab} LSH.vchas1 vs totals клона"
            );
        }
    }

    /**
     * @return list<array{name: string, type: string, length: int, decimal?: int}>
     */
    private function naryadFields(): array
    {
        return [
            ['name' => 'DAT', 'type' => 'D', 'length' => 8],
            ['name' => 'TABNOM', 'type' => 'C', 'length' => 4],
            ['name' => 'WORK', 'type' => 'C', 'length' => 2],
            ['name' => 'SMENA', 'type' => 'C', 'length' => 2],
            ['name' => 'CHAS', 'type' => 'N', 'length' => 6, 'decimal' => 3],
            ['name' => 'LIN1', 'type' => 'N', 'length' => 6, 'decimal' => 3],
            ['name' => 'CHASR1', 'type' => 'N', 'length' => 6, 'decimal' => 3],
            ['name' => 'NOCH1', 'type' => 'N', 'length' => 5, 'decimal' => 2],
            ['name' => 'NOCHR1', 'type' => 'N', 'length' => 5, 'decimal' => 2],
            ['name' => 'VECH1', 'type' => 'N', 'length' => 5, 'decimal' => 2],
            ['name' => 'VECHR1', 'type' => 'N', 'length' => 5, 'decimal' => 2],
            ['name' => 'RAZR1', 'type' => 'N', 'length' => 5, 'decimal' => 2],
            ['name' => 'RAZRR1', 'type' => 'N', 'length' => 5, 'decimal' => 2],
            ['name' => 'PRAZD1', 'type' => 'N', 'length' => 5, 'decimal' => 2],
            ['name' => 'PRAZDR1', 'type' => 'N', 'length' => 5, 'decimal' => 2],
            ['name' => 'PERERAB', 'type' => 'N', 'length' => 5, 'decimal' => 2],
            ['name' => 'REZM2L', 'type' => 'C', 'length' => 1],
        ];
    }

    /**
     * @return list<array{name: string, type: string, length: int, decimal?: int}>
     */
    private function lshFields(): array
    {
        return [
            ['name' => 'TABNOM', 'type' => 'C', 'length' => 4],
            ['name' => 'GODMES', 'type' => 'C', 'length' => 6],
            ['name' => 'DOLZN', 'type' => 'C', 'length' => 3],
            ['name' => 'VCHAS1', 'type' => 'N', 'length' => 6, 'decimal' => 2],
            ['name' => 'VC1', 'type' => 'N', 'length' => 6, 'decimal' => 2],
            ['name' => 'NOCH1', 'type' => 'N', 'length' => 6, 'decimal' => 2],
            ['name' => 'VECH1', 'type' => 'N', 'length' => 6, 'decimal' => 2],
            ['name' => 'RAZR1', 'type' => 'N', 'length' => 6, 'decimal' => 2],
            ['name' => 'PRAZD1', 'type' => 'N', 'length' => 6, 'decimal' => 2],
            ['name' => 'DNI', 'type' => 'N', 'length' => 2],
            ['name' => 'VYH', 'type' => 'N', 'length' => 2],
            ['name' => 'KN', 'type' => 'N', 'length' => 2],
            ['name' => 'TEK_NR', 'type' => 'N', 'length' => 6, 'decimal' => 2],
            ['name' => 'PER1', 'type' => 'N', 'length' => 6, 'decimal' => 2],
            ['name' => 'VISL', 'type' => 'N', 'length' => 6, 'decimal' => 2],
            ['name' => 'PREMM', 'type' => 'N', 'length' => 6, 'decimal' => 2],
            ['name' => 'BRIG', 'type' => 'N', 'length' => 6, 'decimal' => 2],
            ['name' => 'NVIH', 'type' => 'N', 'length' => 6, 'decimal' => 2],
            ['name' => 'DOB1', 'type' => 'N', 'length' => 5, 'decimal' => 2],
            ['name' => 'TEH', 'type' => 'N', 'length' => 6, 'decimal' => 2],
            ['name' => 'MEDK', 'type' => 'N', 'length' => 6, 'decimal' => 2],
            ['name' => 'KL1', 'type' => 'N', 'length' => 6, 'decimal' => 2],
        ];
    }

    /**
     * @return list<array{name: string, type: string, length: int, decimal?: int}>
     */
    private function lsbuhFields(): array
    {
        return [
            ['name' => 'GODMES', 'type' => 'C', 'length' => 6],
            ['name' => 'TABN', 'type' => 'C', 'length' => 4],
            ['name' => 'VOPL', 'type' => 'C', 'length' => 3],
            ['name' => 'TARST', 'type' => 'N', 'length' => 10, 'decimal' => 3],
            ['name' => 'PROCNT', 'type' => 'N', 'length' => 6, 'decimal' => 2],
            ['name' => 'VROTR', 'type' => 'N', 'length' => 6, 'decimal' => 2],
            ['name' => 'ZAKAZ', 'type' => 'C', 'length' => 7],
            ['name' => 'PROF', 'type' => 'C', 'length' => 3],
            ['name' => 'NOMLS', 'type' => 'C', 'length' => 1],
        ];
    }
}
