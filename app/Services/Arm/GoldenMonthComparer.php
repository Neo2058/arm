<?php

namespace App\Services\Arm;

use App\Models\ArmAccount;
use App\Models\ArmMonthNorm;
use App\Models\ArmPersonnel;
use App\Models\NaryadAssignment;
use Carbon\Carbon;

/**
 * Сверка эталонного месяца: NARJAD/CHAS2 ↔ naryad_assignments, LSH ↔ totals, LSBUH ↔ строки ЛС.
 */
class GoldenMonthComparer
{
    /** @var list<string> */
    public const HOUR_FIELDS = ['vchas1', 'vc1', 'noch1', 'vech1', 'razr1', 'prazd1', 'dni', 'vyh', 'kn'];

    /** @var list<string> */
    public const TOTAL_FIELDS = ['tek_nr', 'per1', 'visl', 'premm', 'brig', 'nvih', 'dob1', 'teh', 'medk'];

    /**
     * @param  list<string>  $tabs
     * @return array{
     *     month: string,
     *     tabs: list<string>,
     *     fox_naryad: array<string, array<string, float>>,
     *     fox_lsh: array<string, array<string, float|string>>,
     *     fox_lsbuh: array<string, list<array<string, mixed>>>,
     *     clone_hours: array<string, array<string, float>>,
     *     clone_lsh: array<string, array<string, float>>,
     *     clone_lsbuh: array<string, list<array<string, mixed>>>,
     *     discrepancies: list<array{tab: string, source: string, field: string, fox: mixed, clone: mixed}>
     * }
     */
    public function compare(string $dir, string $yearMonth, array $tabs): array
    {
        $foxNaryad = $this->sumNaryadFiles($dir, $yearMonth, $tabs);
        $foxLsh = $this->loadLsh($dir, $yearMonth, $tabs);
        $foxLsbuh = $this->loadLsbuh($dir, $yearMonth, $tabs);
        $cloneHours = $this->sumCloneAssignments($yearMonth, $tabs);
        $cloneLsh = $this->loadCloneTotals($yearMonth, $tabs);
        $cloneLsbuh = $this->loadCloneLines($yearMonth, $tabs);

        $discrepancies = [];
        foreach ($tabs as $tab) {
            $discrepancies = array_merge(
                $discrepancies,
                $this->diffFields($tab, 'NARJAD↔LSH', $foxNaryad[$tab] ?? [], $foxLsh[$tab] ?? [], self::HOUR_FIELDS),
                $this->diffFields($tab, 'NARJAD↔клон', $foxNaryad[$tab] ?? [], $cloneHours[$tab] ?? [], self::HOUR_FIELDS),
                $this->diffFields($tab, 'LSH↔клон', $foxLsh[$tab] ?? [], $cloneLsh[$tab] ?? [], array_merge(self::HOUR_FIELDS, self::TOTAL_FIELDS)),
                $this->diffLsbuh($tab, $foxLsbuh[$tab] ?? [], $cloneLsbuh[$tab] ?? []),
            );
        }

        return [
            'month' => $yearMonth,
            'tabs' => $tabs,
            'fox_naryad' => $foxNaryad,
            'fox_lsh' => $foxLsh,
            'fox_lsbuh' => $foxLsbuh,
            'clone_hours' => $cloneHours,
            'clone_lsh' => $cloneLsh,
            'clone_lsbuh' => $cloneLsbuh,
            'discrepancies' => $discrepancies,
        ];
    }

    /**
     * @param  list<string>  $tabs
     * @return array<string, array<string, float>>
     */
    public function sumNaryadFiles(string $dir, string $yearMonth, array $tabs): array
    {
        $sums = [];
        foreach ($tabs as $tab) {
            $sums[$tab] = $this->emptyHours();
        }
        $want = array_fill_keys($tabs, true);
        $seen = [];
        foreach ($this->naryadPaths($dir, $yearMonth) as $path) {
            foreach ((new DbfReader($path))->records() as $row) {
                $tab = trim((string) ($row['TABNOM'] ?? ''));
                $date = $row['DAT'] ?? '';
                if (! isset($want[$tab]) || ! is_string($date) || ! str_starts_with($date, $yearMonth)) {
                    continue;
                }
                $key = $tab.'|'.$date;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $this->addNaryadRow($sums[$tab], $row);
            }
        }

        return $sums;
    }

    /**
     * @param  list<string>  $tabs
     * @return array<string, array<string, float|string>>
     */
    public function loadLsh(string $dir, string $yearMonth, array $tabs): array
    {
        $file = $this->findDbf($dir, 'LSH');
        $out = [];
        foreach ($tabs as $tab) {
            $out[$tab] = [];
        }
        if (! $file) {
            return $out;
        }
        $godmes = ArmMonthNorm::toGodmes($yearMonth);
        $want = array_fill_keys($tabs, true);
        foreach ((new DbfReader($file))->records() as $row) {
            if (trim((string) ($row['GODMES'] ?? '')) !== $godmes) {
                continue;
            }
            $tab = trim((string) ($row['TABNOM'] ?? ''));
            if (! isset($want[$tab])) {
                continue;
            }
            $out[$tab] = $this->lshFields($row);
        }

        return $out;
    }

    /**
     * @param  list<string>  $tabs
     * @return array<string, list<array<string, mixed>>>
     */
    public function loadLsbuh(string $dir, string $yearMonth, array $tabs): array
    {
        $file = $this->findDbf($dir, 'LSBUH');
        $out = [];
        foreach ($tabs as $tab) {
            $out[$tab] = [];
        }
        if (! $file) {
            return $out;
        }
        $godmes = ArmMonthNorm::toGodmes($yearMonth);
        $want = array_fill_keys($tabs, true);
        foreach ((new DbfReader($file))->records() as $row) {
            if (trim((string) ($row['GODMES'] ?? '')) !== $godmes) {
                continue;
            }
            $tab = trim((string) ($row['TABN'] ?? $row['TABNOM'] ?? ''));
            if (! isset($want[$tab])) {
                continue;
            }
            $hours = round((float) ($row['VROTR'] ?? 0), 2);
            if (abs($hours) < 0.0005) {
                continue;
            }
            $out[$tab][] = [
                'vopl' => trim((string) ($row['VOPL'] ?? '')),
                'tarst' => round((float) ($row['TARST'] ?? 0), 3),
                'procnt' => round((float) ($row['PROCNT'] ?? 0), 2),
                'vrotr' => $hours,
                'zakaz' => trim((string) ($row['ZAKAZ'] ?? '')),
                'nomls' => trim((string) ($row['NOMLS'] ?? '0')),
            ];
        }

        return $out;
    }

    /**
     * @param  list<string>  $tabs
     * @return array<string, array<string, float>>
     */
    public function sumCloneAssignments(string $yearMonth, array $tabs): array
    {
        $start = Carbon::createFromFormat('Y-m', $yearMonth)->startOfMonth();
        $end = $start->copy()->endOfMonth();
        $personnel = ArmPersonnel::query()->whereIn('tab_number', $tabs)->get()->keyBy('tab_number');
        $out = [];
        foreach ($tabs as $tab) {
            $out[$tab] = $this->emptyHours();
            $userId = $personnel[$tab]->user_id ?? null;
            if (! $userId) {
                continue;
            }
            $rows = NaryadAssignment::query()
                ->where('user_id', $userId)
                ->whereBetween('plan_date', [$start->toDateString(), $end->toDateString()])
                ->get();
            foreach ($rows as $row) {
                $this->addAssignment($out[$tab], $row);
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $tabs
     * @return array<string, array<string, float>>
     */
    public function loadCloneTotals(string $yearMonth, array $tabs): array
    {
        $out = [];
        foreach ($tabs as $tab) {
            $out[$tab] = [];
            $personnel = ArmPersonnel::query()->where('tab_number', $tab)->first();
            if (! $personnel) {
                continue;
            }
            $account = ArmAccount::query()
                ->where('user_id', $personnel->user_id)
                ->where('year_month', $yearMonth)
                ->where('kind', 'primary')
                ->first();
            if (! $account) {
                continue;
            }
            $totals = is_array($account->totals) ? $account->totals : [];
            $out[$tab] = [];
            foreach (array_merge(self::HOUR_FIELDS, self::TOTAL_FIELDS) as $field) {
                $out[$tab][$field] = (float) ($totals[$field] ?? 0);
            }
            $out[$tab]['dolzn'] = (string) ($account->position_code ?? '');
        }

        return $out;
    }

    /**
     * @param  list<string>  $tabs
     * @return array<string, list<array<string, mixed>>>
     */
    public function loadCloneLines(string $yearMonth, array $tabs): array
    {
        $out = [];
        foreach ($tabs as $tab) {
            $out[$tab] = [];
            $personnel = ArmPersonnel::query()->where('tab_number', $tab)->first();
            if (! $personnel) {
                continue;
            }
            $account = ArmAccount::query()
                ->with('lines')
                ->where('user_id', $personnel->user_id)
                ->where('year_month', $yearMonth)
                ->where('kind', 'primary')
                ->first();
            if (! $account) {
                continue;
            }
            foreach ($account->lines as $line) {
                $hours = round((float) $line->hours, 2);
                if (abs($hours) < 0.0005) {
                    continue;
                }
                $out[$tab][] = [
                    'vopl' => trim((string) $line->pay_code),
                    'tarst' => round((float) $line->tariff, 3),
                    'procnt' => round((float) $line->percent, 2),
                    'vrotr' => $hours,
                    'zakaz' => trim((string) ($line->cost_code ?? '')),
                    'nomls' => trim((string) ($line->ls_number ?? '0')),
                ];
            }
        }

        return $out;
    }

    /**
     * @return array<string, float>
     */
    public function emptyHours(): array
    {
        return [
            'vchas1' => 0.0, 'vc1' => 0.0, 'noch1' => 0.0, 'vech1' => 0.0,
            'razr1' => 0.0, 'prazd1' => 0.0, 'dni' => 0.0, 'vyh' => 0.0, 'kn' => 0.0,
        ];
    }

    /**
     * @param  array<string, float>  $acc
     * @param  array<string, mixed>  $row
     */
    private function addNaryadRow(array &$acc, array $row): void
    {
        $shift = trim((string) ($row['SMENA'] ?? $row['SM'] ?? ''));
        $work = mb_strtoupper(trim((string) ($row['WORK'] ?? $row['NM'] ?? '')));
        if ($shift !== '') {
            $acc['vchas1'] += (float) ($row['LIN1'] ?? 0);
            $acc['noch1'] += (float) ($row['NOCH1'] ?? 0);
            $acc['vech1'] += (float) ($row['VECH1'] ?? 0);
            $acc['razr1'] += (float) ($row['RAZR1'] ?? 0);
            $acc['prazd1'] += (float) ($row['PRAZD1'] ?? 0);
            $acc['dni'] += 1.0;
            if (in_array($shift, ['3+', '4+'], true)) {
                $acc['kn'] += 1.0;
            }
        } elseif ($work !== '' && (str_starts_with($work, 'В') || str_starts_with($work, 'Е') || str_starts_with($work, 'ВЫХ'))) {
            $acc['vyh'] += 1.0;
        }
        $acc['vc1'] = $acc['vchas1'] - $acc['prazd1'];
    }

    /**
     * @param  array<string, float>  $acc
     */
    private function addAssignment(array &$acc, NaryadAssignment $row): void
    {
        $shift = trim((string) ($row->shift_code ?? ''));
        $work = mb_strtoupper(trim((string) ($row->work_code ?: $row->route_number)));
        if ($shift !== '') {
            $acc['vchas1'] += (float) $row->hours_line;
            $acc['noch1'] += (float) $row->hours_night;
            $acc['vech1'] += (float) $row->hours_evening;
            $acc['razr1'] += (float) $row->hours_break;
            $acc['prazd1'] += (float) $row->hours_holiday;
            $acc['dni'] += 1.0;
            if (in_array($shift, ['3+', '4+'], true)) {
                $acc['kn'] += 1.0;
            }
        } elseif ($work !== '' && (str_starts_with($work, 'В') || str_starts_with($work, 'Е') || str_starts_with($work, 'ВЫХ'))) {
            $acc['vyh'] += 1.0;
        }
        $acc['vc1'] = $acc['vchas1'] - $acc['prazd1'];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, float|string>
     */
    private function lshFields(array $row): array
    {
        $map = [
            'vchas1' => 'VCHAS1', 'vc1' => 'VC1', 'noch1' => 'NOCH1', 'vech1' => 'VECH1',
            'razr1' => 'RAZR1', 'prazd1' => 'PRAZD1', 'dni' => 'DNI', 'vyh' => 'VYH', 'kn' => 'KN',
            'tek_nr' => 'TEK_NR', 'per1' => 'PER1', 'visl' => 'VISL', 'premm' => 'PREMM',
            'brig' => 'BRIG', 'nvih' => 'NVIH', 'dob1' => 'DOB1', 'teh' => 'TEH', 'medk' => 'MEDK',
            'kl1' => 'KL1', 'kl2' => 'KL2', 'kl3' => 'KL3',
        ];
        $out = ['dolzn' => trim((string) ($row['DOLZN'] ?? ''))];
        foreach ($map as $key => $src) {
            $out[$key] = (float) ($row[$src] ?? 0);
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     * @param  list<string>  $fields
     * @return list<array{tab: string, source: string, field: string, fox: mixed, clone: mixed}>
     */
    private function diffFields(string $tab, string $source, array $left, array $right, array $fields): array
    {
        $out = [];
        foreach ($fields as $field) {
            $a = (float) ($left[$field] ?? 0);
            $b = (float) ($right[$field] ?? 0);
            if (abs($a - $b) > 0.015) {
                $out[] = [
                    'tab' => $tab,
                    'source' => $source,
                    'field' => $field,
                    'fox' => round($a, 3),
                    'clone' => round($b, 3),
                ];
            }
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $fox
     * @param  list<array<string, mixed>>  $clone
     * @return list<array{tab: string, source: string, field: string, fox: mixed, clone: mixed}>
     */
    private function diffLsbuh(string $tab, array $fox, array $clone): array
    {
        $out = [];
        $foxMap = [];
        foreach ($fox as $line) {
            $foxMap[$this->lsbuhKey($line)] = $line;
        }
        $cloneMap = [];
        foreach ($clone as $line) {
            $cloneMap[$this->lsbuhKey($line)] = $line;
        }
        foreach ($foxMap as $key => $line) {
            if (! isset($cloneMap[$key])) {
                $out[] = [
                    'tab' => $tab,
                    'source' => 'LSBUH↔клон',
                    'field' => $key,
                    'fox' => $line['vrotr'],
                    'clone' => 'нет строки',
                ];

                continue;
            }
            $c = $cloneMap[$key];
            foreach (['vrotr', 'procnt', 'tarst'] as $field) {
                if (abs((float) $line[$field] - (float) $c[$field]) > 0.015) {
                    $out[] = [
                        'tab' => $tab,
                        'source' => 'LSBUH↔клон',
                        'field' => $key.'.'.$field,
                        'fox' => $line[$field],
                        'clone' => $c[$field],
                    ];
                }
            }
        }
        foreach ($cloneMap as $key => $line) {
            if (! isset($foxMap[$key])) {
                $out[] = [
                    'tab' => $tab,
                    'source' => 'LSBUH↔клон',
                    'field' => $key,
                    'fox' => 'нет строки',
                    'clone' => $line['vrotr'],
                ];
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function lsbuhKey(array $line): string
    {
        return ($line['vopl'] ?? '').'|'.($line['nomls'] ?? '0');
    }

    /**
     * @return list<string>
     */
    private function naryadPaths(string $dir, string $yearMonth): array
    {
        $out = [];
        $this->pushDbf($out, $dir.DIRECTORY_SEPARATOR.'NARJAD.DBF');
        if (preg_match('/^(\d{4})-(\d{2})$/', $yearMonth, $m)) {
            $this->pushDbf($out, $dir.DIRECTORY_SEPARATOR.'NR'.$m[2].substr($m[1], 2, 2).'.DBF');
        }

        return $out;
    }

    /**
     * @param  list<string>  $out
     */
    private function pushDbf(array &$out, string $path): void
    {
        if (! is_file($path)) {
            return;
        }
        $real = realpath($path) ?: $path;
        if (! in_array($real, $out, true)) {
            $out[] = $real;
        }
    }

    private function findDbf(string $dir, string $stem): ?string
    {
        foreach ([$dir.DIRECTORY_SEPARATOR.$stem.'.DBF', $dir.DIRECTORY_SEPARATOR.$stem.'.dbf'] as $path) {
            if (is_file($path)) {
                return realpath($path) ?: $path;
            }
        }

        return null;
    }
}
