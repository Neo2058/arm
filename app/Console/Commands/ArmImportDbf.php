<?php

namespace App\Console\Commands;

use App\Models\ArmHoliday;
use App\Models\ArmShiftBreakdown;
use App\Models\NaryadQuota;
use App\Models\ScheduleType;
use App\Services\Arm\AccountingImporter;
use App\Services\Arm\DbfReader;
use App\Services\Arm\PersonnelImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Finder\Finder;

class ArmImportDbf extends Command
{
    protected $signature = 'arm:import-dbf
                            {path? : Каталог с DBF (Datanrd) или путь к ARMNRD}
                            {--only= : graphs,breakdowns,holidays,calendar,personnel,appointments,absences,adjustments,formulas,chas2}';

    protected $description = 'Импорт справочников АРМ-ЛБ из FoxPro DBF';

    public function handle(): int
    {
        $path = $this->resolveDataDir($this->argument('path'));
        if (! $path) {
            $this->error('Каталог с DBF не найден. Передайте путь к Datanrd или корню ARMNRD.');

            return self::FAILURE;
        }

        $only = $this->option('only');
        $targets = $only
            ? array_filter(array_map('trim', explode(',', strtolower((string) $only))))
            : ['graphs', 'breakdowns', 'holidays', 'calendar', 'personnel', 'appointments', 'absences', 'adjustments', 'formulas', 'chas2'];

        $this->info('Источник: '.$path);

        if (in_array('graphs', $targets, true)) {
            $this->importGraphs($path);
        }
        if (in_array('breakdowns', $targets, true)) {
            $this->importBreakdowns($path);
        }
        if (in_array('holidays', $targets, true)) {
            $this->importHolidays($path);
        }
        if (in_array('calendar', $targets, true)) {
            $this->importCalendar($path);
        }
        if (in_array('personnel', $targets, true)) {
            $this->importPersonnel($path);
        }
        if (in_array('appointments', $targets, true)) {
            $this->importAppointments($path);
        }
        if (in_array('absences', $targets, true)) {
            $this->importAbsences($path);
        }
        if (in_array('adjustments', $targets, true)) {
            $this->importAdjustments($path);
        }
        if (in_array('formulas', $targets, true)) {
            $count = (new AccountingImporter)->importFormulas($path);
            $this->info("Формулы ЛС: {$count}");
        }
        if (in_array('chas2', $targets, true)) {
            $count = (new AccountingImporter)->importSecondPersonHours($path);
            $this->info("Часы 2 лица: {$count}");
        }

        $this->info('Готово. Схема — armd.md');

        return self::SUCCESS;
    }

    private function resolveDataDir(?string $path): ?string
    {
        $candidates = array_filter([
            $path,
            $path ? $path.DIRECTORY_SEPARATOR.'Datanrd' : null,
            config('arm.dbf_path'),
            env('ARM_DBF_PATH'),
            '/Users/vyacheslavandreevich/Downloads/ARMNRD/Datanrd',
        ]);

        foreach ($candidates as $candidate) {
            if (is_dir($candidate) && $this->findDbf($candidate, ['GRAF', 'RAZBSM', 'PRAZD', 'KALEND', 'LKM'])) {
                return $candidate;
            }
            if (is_dir($candidate.DIRECTORY_SEPARATOR.'Datanrd')
                && $this->findDbf($candidate.DIRECTORY_SEPARATOR.'Datanrd', ['GRAF'])) {
                return $candidate.DIRECTORY_SEPARATOR.'Datanrd';
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $stems
     */
    private function findDbf(string $dir, array $stems): ?string
    {
        foreach ($stems as $stem) {
            $found = $this->dbfPath($dir, $stem);
            if ($found) {
                return $found;
            }
        }

        return null;
    }

    private function dbfPath(string $dir, string $stem): ?string
    {
        if (! is_dir($dir)) {
            return null;
        }

        $finder = (new Finder)->files()->in($dir)->depth('== 0')->name('/^'.$stem.'\.dbf$/i');
        foreach ($finder as $file) {
            return $file->getRealPath();
        }

        return null;
    }

    private function importGraphs(string $dir): void
    {
        $file = $this->dbfPath($dir, 'GRAF');
        if (! $file) {
            $this->warn('GRAF.DBF не найден');

            return;
        }

        $count = 0;
        foreach ((new DbfReader($file))->records() as $row) {
            $code = trim((string) ($row['KODEL'] ?? ''));
            if ($code === '') {
                continue;
            }
            $name = trim((string) ($row['NAZEL'] ?? '')) ?: $code;
            ScheduleType::updateOrCreate(
                ['foxpro_code' => $code],
                [
                    'name' => $name,
                    'description' => trim('цвет='.($row['ZVET'] ?? '').'; переход='.($row['PERNM'] ?? ''), '; '),
                ]
            );
            $count++;
        }
        $this->info("Графики: {$count}");
    }

    private function importBreakdowns(string $dir): void
    {
        $file = $this->dbfPath($dir, 'RAZBSM');
        if (! $file) {
            $this->warn('RAZBSM.DBF не найден');

            return;
        }

        if (! Schema::hasTable('arm_shift_breakdowns')) {
            $this->error('Нет таблицы arm_shift_breakdowns — прогоните миграции.');

            return;
        }

        $types = ScheduleType::query()->whereNotNull('foxpro_code')->pluck('id', 'foxpro_code');
        $chunk = [];
        $count = 0;
        $now = now();

        foreach ((new DbfReader($file))->records() as $row) {
            $graph = trim((string) ($row['GRAF'] ?? ''));
            $route = trim((string) ($row['NM'] ?? ''));
            $shift = trim((string) ($row['SM'] ?? ''));
            if ($graph === '' && $route === '' && $shift === '') {
                continue;
            }
            $chunk[] = [
                'schedule_type_id' => $types[$graph] ?? null,
                'graph_code' => $graph,
                'route_code' => $route,
                'shift_code' => $shift,
                'sequence' => trim((string) ($row['NOM'] ?? '')),
                'position_code' => trim((string) ($row['DOL'] ?? '')),
                'start_hours' => (float) ($row['NACH'] ?? 0),
                'end_hours' => (float) ($row['OKON'] ?? 0),
                'hours_total' => (float) ($row['CHAS'] ?? 0),
                'hours_line' => (float) ($row['LIN1'] ?? 0),
                'hours_line_2' => (float) ($row['LIN2'] ?? 0),
                'hours_reserve' => (float) ($row['CHASR1'] ?? 0),
                'hours_reserve_2' => (float) ($row['CHASR2'] ?? 0),
                'hours_night' => (float) ($row['NOCH1'] ?? 0),
                'hours_night_reserve' => (float) ($row['NOCHR1'] ?? 0),
                'hours_night_2' => (float) ($row['NOCH2'] ?? 0),
                'hours_night_2_reserve' => (float) ($row['NOCHR2'] ?? 0),
                'hours_evening' => (float) ($row['VECH1'] ?? 0),
                'hours_evening_reserve' => (float) ($row['VECHR1'] ?? 0),
                'hours_evening_2' => (float) ($row['VECH2'] ?? 0),
                'hours_evening_2_reserve' => (float) ($row['VECHR2'] ?? 0),
                'hours_break' => (float) ($row['RAZR1'] ?? 0),
                'hours_break_reserve' => (float) ($row['RAZRR1'] ?? 0),
                'hours_break_2' => (float) ($row['RAZR2'] ?? 0),
                'hours_break_2_reserve' => (float) ($row['RAZRR2'] ?? 0),
                'appearance_start' => $this->nullableString($row['PLZAST'] ?? null),
                'appearance_end' => $this->nullableString($row['PLOKON'] ?? null),
                'content' => $this->nullableString($row['SODER'] ?? null),
                'morning_code' => $this->nullableString($row['UTRO'] ?? null),
                'morning_shift' => $this->nullableString($row['SMUT'] ?? null),
                'notes' => $this->nullableString(mb_substr((string) ($row['PRIM'] ?? ''), 0, 255)),
                'created_at' => $now,
                'updated_at' => $now,
            ];
            if (count($chunk) >= 200) {
                $this->upsertBreakdowns($chunk);
                $count += count($chunk);
                $chunk = [];
            }
        }
        if ($chunk !== []) {
            $this->upsertBreakdowns($chunk);
            $count += count($chunk);
        }

        $this->info("Разбивки смен: {$count}");
    }

    /**
     * @param  list<array<string, mixed>>  $chunk
     */
    private function upsertBreakdowns(array $chunk): void
    {
        ArmShiftBreakdown::upsert(
            $chunk,
            ['graph_code', 'route_code', 'shift_code', 'sequence', 'position_code'],
            [
                'schedule_type_id', 'start_hours', 'end_hours', 'hours_total',
                'hours_line', 'hours_line_2', 'hours_reserve', 'hours_reserve_2',
                'hours_night', 'hours_night_reserve', 'hours_night_2', 'hours_night_2_reserve',
                'hours_evening', 'hours_evening_reserve', 'hours_evening_2', 'hours_evening_2_reserve',
                'hours_break', 'hours_break_reserve', 'hours_break_2', 'hours_break_2_reserve',
                'appearance_start', 'appearance_end', 'content', 'morning_code', 'morning_shift',
                'notes', 'updated_at',
            ]
        );
    }

    private function importHolidays(string $dir): void
    {
        $file = $this->dbfPath($dir, 'PRAZD');
        if (! $file) {
            $this->warn('PRAZD.DBF не найден');

            return;
        }

        $count = 0;
        foreach ((new DbfReader($file))->records() as $row) {
            $date = $row['KODEL'] ?? null;
            if (! $date) {
                continue;
            }
            ArmHoliday::updateOrCreate(
                ['holiday_date' => $date],
                ['name' => $this->nullableString($row['NAZEL'] ?? null)]
            );
            $count++;
        }
        $this->info("Праздники: {$count}");
    }

    private function importCalendar(string $dir): void
    {
        $file = $this->dbfPath($dir, 'KALEND');
        if (! $file) {
            $this->warn('KALEND.DBF не найден');

            return;
        }

        $types = ScheduleType::query()->whereNotNull('foxpro_code')->pluck('id', 'foxpro_code');
        $count = 0;
        foreach ((new DbfReader($file))->records() as $row) {
            $date = $row['DAT'] ?? null;
            if (! $date) {
                continue;
            }
            $code = trim((string) ($row['TIPGR'] ?? ''));
            NaryadQuota::updateOrCreate(
                ['plan_date' => $date],
                [
                    'schedule_type_id' => $code !== '' ? ($types[$code] ?? null) : null,
                ]
            );
            $count++;
        }
        $this->info("Календарь: {$count}");
    }

    private function importPersonnel(string $dir): void
    {
        $file = $this->dbfPath($dir, 'LKM');
        if (! $file) {
            $this->warn('LKM.DBF не найден');

            return;
        }
        $count = (new PersonnelImporter)->importPersonnel($file);
        $this->info("Картотека: {$count}");
    }

    private function importAppointments(string $dir): void
    {
        $file = $this->dbfPath($dir, 'NAZN');
        if (! $file) {
            $this->warn('NAZN.DBF не найден');

            return;
        }
        $count = (new PersonnelImporter)->importAppointments($file);
        $this->info("Назначения: {$count}");
    }

    private function importAbsences(string $dir): void
    {
        $file = $this->dbfPath($dir, 'OTVM');
        if (! $file) {
            $this->warn('OTVM.DBF не найден');

            return;
        }
        $count = (new PersonnelImporter)->importAbsences($file);
        $this->info("Отвлечения: {$count}");
    }

    private function importAdjustments(string $dir): void
    {
        $file = $this->dbfPath($dir, 'POZEL');
        if (! $file) {
            $this->warn('POZEL.DBF не найден');

            return;
        }
        $count = (new PersonnelImporter)->importDayAdjustments($file);
        $this->info("Подстройки дней: {$count}");
    }

    private function nullableString(mixed $value): ?string
    {
        $s = trim((string) $value);

        return $s === '' ? null : $s;
    }
}
