<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\ArmMonthNorm;
use App\Models\ArmPayFormula;
use App\Models\ArmPersonnel;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\Arm\AccountBuilder;
use App\Services\Arm\AccountingImporter;
use App\Services\Arm\DbfReader;
use App\Services\Arm\GoldenMonthComparer;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ArmGoldenMonth extends Command
{
    protected $signature = 'arm:golden-month
                            {month=2018-11 : Эталонный месяц YYYY-MM}
                            {path? : Каталог Datanrd или корень ARMNRD}
                            {--tabs=0321,0428,2575 : Табельные через запятую}
                            {--compare-only : Только сверка, без импорта и сборки ЛС}';

    protected $description = 'Эталонный месяц АРМ-ЛБ: импорт NARJAD и сверка с LSH / LSBUH';

    public function handle(AccountingImporter $importer, AccountBuilder $builder, GoldenMonthComparer $comparer): int
    {
        $month = (string) $this->argument('month');
        if (! preg_match('/^\d{4}-\d{2}$/', $month)) {
            $this->error('Месяц должен быть YYYY-MM.');

            return self::FAILURE;
        }
        $dir = $this->resolveDataDir($this->argument('path'));
        if (! $dir) {
            $this->error('Каталог с DBF не найден.');

            return self::FAILURE;
        }
        $tabs = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('tabs')))));
        if ($tabs === []) {
            $this->error('Нужен хотя бы один табельный.');

            return self::FAILURE;
        }

        $this->info("Эталон {$month}, табельные: ".implode(', ', $tabs));
        $this->info('Источник: '.$dir);

        if (! $this->option('compare-only')) {
            $this->seedPersonnel($dir, $month, $tabs);
            $naryad = $importer->importNaryad($dir, $month, $tabs);
            $this->info("Импорт NARJAD: {$naryad}");
            $chas2 = $importer->importSecondPersonHours($dir, $month, $tabs);
            $this->info("Импорт CHAS2: {$chas2}");
            if (ArmPayFormula::query()->count() === 0) {
                $formulas = $importer->importFormulas($dir);
                $this->info("Импорт формул: {$formulas}");
            }
            $importer->importPremiumRates($dir);
            $importer->importSeniorityBands($dir);
            $importer->importExtraPays($dir);
            $importer->importMonthPremiums($dir);
            $importer->importTariffs($dir);
            $this->seedMonthNorm($dir, $month, $tabs);
            $built = $builder->buildMonth($month);
            $this->info("Собрано ЛС: {$built['accounts']} счетов, {$built['lines']} строк");
        }

        $report = $comparer->compare($dir, $month, $tabs);
        $this->printHours($report);
        $this->printDiscrepancies($report['discrepancies']);

        $count = count($report['discrepancies']);
        if ($count === 0) {
            $this->info('Расхождений нет (порог 0.015).');

            return self::SUCCESS;
        }
        $this->warn("Расхождений: {$count}");

        return self::FAILURE;
    }

    /**
     * @param  list<string>  $tabs
     */
    private function seedPersonnel(string $dir, string $month, array $tabs): void
    {
        $lsh = (new GoldenMonthComparer)->loadLsh($dir, $month, $tabs);
        $names = $this->tehuchNames($dir, $month, $tabs);
        foreach ($tabs as $tab) {
            if (ArmPersonnel::query()->where('tab_number', $tab)->exists()) {
                continue;
            }
            $row = $lsh[$tab] ?? [];
            $position = trim((string) ($row['dolzn'] ?? 'МШ')) ?: 'МШ';
            $name = $names[$tab] ?? ('таб. '.$tab);
            $user = User::create([
                'name' => $name,
                'email' => 'tab'.$tab.'@arm.local',
                'password' => Str::random(40),
                'role' => UserRole::DRIVER,
                'is_active' => true,
            ]);
            UserProfile::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'tab_number' => $tab,
                    'position' => $position,
                    'is_pomoshnik' => str_contains($position, 'П'),
                    'is_brigadir' => ((float) ($row['brig'] ?? 0)) > 0,
                ]
            );
            $class = null;
            if (((float) ($row['kl1'] ?? 0)) > 0) {
                $class = '1';
            } elseif (((float) ($row['kl2'] ?? 0)) > 0) {
                $class = '2';
            } elseif (((float) ($row['kl3'] ?? 0)) > 0) {
                $class = '3';
            }
            ArmPersonnel::create([
                'user_id' => $user->id,
                'tab_number' => $tab,
                'full_name' => $name,
                'position_code' => $position,
                'class_code' => $class,
                'is_brigadier' => ((float) ($row['brig'] ?? 0)) > 0,
            ]);
            $this->line("Карточка-заглушка {$tab} {$name} {$position}");
        }
    }

    /**
     * @param  list<string>  $tabs
     * @return array<string, string>
     */
    private function tehuchNames(string $dir, string $month, array $tabs): array
    {
        $file = $dir.DIRECTORY_SEPARATOR.'TEHUCH.DBF';
        if (! is_file($file)) {
            $file = $dir.DIRECTORY_SEPARATOR.'Tehuch.dbf';
        }
        if (! is_file($file)) {
            return [];
        }
        $godmes = ArmMonthNorm::toGodmes($month);
        $want = array_fill_keys($tabs, true);
        $out = [];
        foreach ((new DbfReader($file))->records() as $row) {
            if (trim((string) ($row['GODMES'] ?? '')) !== $godmes) {
                continue;
            }
            $tab = trim((string) ($row['TABNOM'] ?? ''));
            if (! isset($want[$tab])) {
                continue;
            }
            $fio = trim((string) ($row['FIO'] ?? ''));
            if ($fio !== '') {
                $out[$tab] = rtrim($fio, '+* ');
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $tabs
     */
    private function seedMonthNorm(string $dir, string $month, array $tabs): void
    {
        if (ArmMonthNorm::query()->where('year_month', $month)->exists()) {
            return;
        }
        $lsh = (new GoldenMonthComparer)->loadLsh($dir, $month, $tabs);
        $tek = 0.0;
        foreach ($lsh as $row) {
            $tek = max($tek, (float) ($row['tek_nr'] ?? 0));
        }
        if ($tek <= 0) {
            $this->warn("NRCHAS для {$month} нет, LSH.TEK_NR пуст — норма 0.");

            return;
        }
        $days = $this->workdays($month);
        ArmMonthNorm::create([
            'year_month' => $month,
            'month_hours' => $tek,
            'day_hours' => $days > 0 ? round($tek / $days, 3) : 0,
        ]);
        $this->info("Норма {$month} из LSH.TEK_NR: {$tek} (NRCHAS в дампе с 2021).");
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function printHours(array $report): void
    {
        $this->table(
            ['таб', 'поле', 'NARJAD', 'LSH', 'клон'],
            $this->hourRows($report)
        );
    }

    /**
     * @param  array<string, mixed>  $report
     * @return list<list<string>>
     */
    private function hourRows(array $report): array
    {
        $rows = [];
        foreach ($report['tabs'] as $tab) {
            foreach (GoldenMonthComparer::HOUR_FIELDS as $field) {
                $rows[] = [
                    $tab,
                    $field,
                    $this->fmt($report['fox_naryad'][$tab][$field] ?? 0),
                    $this->fmt($report['fox_lsh'][$tab][$field] ?? 0),
                    $this->fmt($report['clone_hours'][$tab][$field] ?? 0),
                ];
            }
        }

        return $rows;
    }

    /**
     * @param  list<array{tab: string, source: string, field: string, fox: mixed, clone: mixed}>  $rows
     */
    private function printDiscrepancies(array $rows): void
    {
        if ($rows === []) {
            return;
        }
        $this->table(
            ['таб', 'срез', 'поле', 'FoxPro', 'клон'],
            array_map(fn ($r) => [$r['tab'], $r['source'], $r['field'], (string) $r['fox'], (string) $r['clone']], $rows)
        );
    }

    private function fmt(mixed $value): string
    {
        return number_format((float) $value, 3, '.', '');
    }

    private function resolveDataDir(mixed $path): ?string
    {
        if (is_string($path) && $path !== '') {
            if (is_dir($path.DIRECTORY_SEPARATOR.'Datanrd')) {
                return $path.DIRECTORY_SEPARATOR.'Datanrd';
            }
            if (is_dir($path)) {
                return $path;
            }
        }
        $candidates = array_filter([
            config('arm.dbf_path'),
            env('ARM_DBF_PATH'),
            '/Users/vyacheslavandreevich/Downloads/ARMNRD/Datanrd',
        ]);
        foreach ($candidates as $candidate) {
            if (is_dir($candidate) && is_file($candidate.DIRECTORY_SEPARATOR.'NARJAD.DBF')) {
                return $candidate;
            }
            if (is_dir($candidate.DIRECTORY_SEPARATOR.'Datanrd')) {
                return $candidate.DIRECTORY_SEPARATOR.'Datanrd';
            }
        }

        return null;
    }

    private function workdays(string $yearMonth): int
    {
        $start = Carbon::createFromFormat('Y-m', $yearMonth)->startOfMonth();
        $end = $start->copy()->endOfMonth();
        $n = 0;
        for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
            if (! $d->isSunday()) {
                $n++;
            }
        }

        return $n;
    }
}
