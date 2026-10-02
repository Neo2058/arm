<?php

namespace App\Services\Arm;

use App\Models\ArmExtraPay;
use App\Models\ArmMonthNorm;
use App\Models\ArmMonthPremium;
use App\Models\ArmPayFormula;
use App\Models\ArmPayKind;
use App\Models\ArmPersonnel;
use App\Models\ArmPremiumRate;
use App\Models\ArmSeniorityBand;
use App\Models\ArmTariff;
use App\Models\NaryadAssignment;

class AccountingImporter
{
    public function importFormulas(string $dir): int
    {
        $count = 0;
        $map = [
            'FLSM' => 'machinist',
            'FLSP' => 'assistant',
        ];
        foreach ($map as $stem => $kind) {
            $file = $this->findDbf($dir, $stem);
            if (! $file) {
                continue;
            }
            foreach ((new DbfReader($file))->records() as $row) {
                $nom = (int) ($row['NOM'] ?? 0);
                $formula = trim((string) ($row['FORMULA'] ?? ''));
                if ($nom < 1 || $formula === '') {
                    continue;
                }
                ArmPayFormula::updateOrCreate(
                    ['kind' => $kind, 'nom' => $nom],
                    [
                        'name' => trim((string) ($row['NAZV'] ?? '')) ?: 'строка '.$nom,
                        'percent_text' => $this->nullable($row['PROC'] ?? null),
                        'percent_source' => $this->nullable($row['FPROC'] ?? null),
                        'percent' => (float) ($row['PROCR'] ?? $row['PROC'] ?? 100),
                        'pay_code' => $this->nullable($row['VIDOPL'] ?? null),
                        'tariff_code' => $this->nullable($row['TARIF'] ?? null),
                        'tariff' => (float) ($row['N_TARIF'] ?? $row['CHTAR'] ?? 0),
                        'cost_code' => $this->nullable($row['SHZAT'] ?? null),
                        'formula' => $formula,
                        'selected' => true,
                    ]
                );
                $count++;
            }
        }

        return $count;
    }

    public function importMonthNorms(string $dir): int
    {
        $file = $this->findDbf($dir, 'NRCHAS');
        if (! $file) {
            return 0;
        }
        $count = 0;
        foreach ((new DbfReader($file))->records() as $row) {
            $yearMonth = ArmMonthNorm::fromMesgod((string) ($row['MESGOD'] ?? ''));
            if (! $yearMonth) {
                continue;
            }
            ArmMonthNorm::updateOrCreate(
                ['year_month' => $yearMonth],
                [
                    'month_hours' => (float) ($row['MESNR'] ?? 0),
                    'day_hours' => (float) ($row['DNNR'] ?? 0),
                ]
            );
            $count++;
        }

        return $count;
    }

    public function importPremiumRates(string $dir): int
    {
        $file = $this->findDbf($dir, 'PREM');
        if (! $file) {
            return 0;
        }
        $count = 0;
        foreach ((new DbfReader($file))->records() as $row) {
            $position = trim((string) ($row['DOLZN'] ?? ''));
            if ($position === '') {
                continue;
            }
            ArmPremiumRate::updateOrCreate(
                [
                    'position_code' => $position,
                    'is_brigadier' => $this->isPlus($row['PBR'] ?? ''),
                ],
                [
                    'percent' => (float) ($row['PROC'] ?? 0),
                    'name' => $this->nullable($row['NAZV'] ?? null),
                ]
            );
            $count++;
        }

        return $count;
    }

    public function importSeniorityBands(string $dir): int
    {
        $file = $this->findDbf($dir, 'VISL');
        if (! $file) {
            return 0;
        }
        $count = 0;
        foreach ((new DbfReader($file))->records() as $row) {
            $from = (int) ($row['OT'] ?? 0);
            $to = (int) ($row['DO'] ?? 0);
            $percent = (float) ($row['PROC'] ?? 0);
            if ($from === 0 && $to === 0 && $percent == 0.0) {
                continue;
            }
            ArmSeniorityBand::updateOrCreate(
                ['years_from' => $from, 'years_to' => $to],
                ['percent' => $percent]
            );
            $count++;
        }

        return $count;
    }

    public function importExtraPays(string $dir): int
    {
        $file = $this->findDbf($dir, 'TEHUCH');
        if (! $file) {
            return 0;
        }
        $personnel = ArmPersonnel::query()->pluck('user_id', 'tab_number');
        $chunk = [];
        $count = 0;
        $now = now();
        foreach ((new DbfReader($file))->records() as $row) {
            $yearMonth = ArmMonthNorm::fromGodmes((string) ($row['GODMES'] ?? ''));
            $tab = trim((string) ($row['TABNOM'] ?? ''));
            if (! $yearMonth || $tab === '') {
                continue;
            }
            $chunk[] = [
                'year_month' => $yearMonth,
                'user_id' => $personnel[$tab] ?? null,
                'tab_number' => $tab,
                'full_name' => $this->nullable($row['FIO'] ?? null),
                'position_code' => $this->nullable($row['DOLZN'] ?? null),
                'hours_tech' => (float) ($row['CHAS'] ?? 0),
                'tech_on' => $row['DAT'] ?? null,
                'hours_accident' => (float) ($row['AVAR'] ?? 0),
                'accident_on' => $row['DATAV'] ?? null,
                'hours_med' => (float) ($row['MEDK'] ?? 0),
                'med_on' => $row['DTMEDK'] ?? null,
                'extra_days_off' => (int) ($row['NVIH'] ?? 0),
                'extra_hours_off' => (float) ($row['NVIH_CH'] ?? 0),
                'created_at' => $now,
                'updated_at' => $now,
            ];
            if (count($chunk) >= 200) {
                $this->upsertExtraPays($chunk);
                $count += count($chunk);
                $chunk = [];
            }
        }
        if ($chunk !== []) {
            $this->upsertExtraPays($chunk);
            $count += count($chunk);
        }

        return $count;
    }

    public function importMonthPremiums(string $dir): int
    {
        $file = $this->findDbf($dir, 'SPPREM');
        if (! $file) {
            return 0;
        }
        $personnel = ArmPersonnel::query()->pluck('user_id', 'tab_number');
        $chunk = [];
        $count = 0;
        $now = now();
        foreach ((new DbfReader($file))->records() as $row) {
            $yearMonth = ArmMonthNorm::fromGodmes((string) ($row['GODMES'] ?? ''));
            $tab = trim((string) ($row['TABNOM'] ?? ''));
            if (! $yearMonth || $tab === '') {
                continue;
            }
            $chunk[] = [
                'year_month' => $yearMonth,
                'user_id' => $personnel[$tab] ?? null,
                'tab_number' => $tab,
                'position_code' => $this->nullable($row['DOLZN'] ?? null) ?? '',
                'percent_plan' => (float) ($row['PREMP'] ?? 0),
                'percent_fact' => (float) ($row['PREMF'] ?? 0),
                'ktu' => (float) ($row['KTU'] ?? 1),
                'note' => $this->nullable($row['PRIM'] ?? null),
                'created_at' => $now,
                'updated_at' => $now,
            ];
            if (count($chunk) >= 200) {
                $this->upsertMonthPremiums($chunk);
                $count += count($chunk);
                $chunk = [];
            }
        }
        if ($chunk !== []) {
            $this->upsertMonthPremiums($chunk);
            $count += count($chunk);
        }

        return $count;
    }

    public function importTariffs(string $dir): int
    {
        $file = $this->findDbf($dir, 'ELKODIF');
        if (! $file) {
            return 0;
        }
        $tariffs = 0;
        $kinds = 0;
        foreach ((new DbfReader($file))->records() as $row) {
            $spr = trim((string) ($row['KODSPR'] ?? ''));
            $code = trim((string) ($row['KODEL'] ?? ''));
            $name = trim((string) ($row['NAZEL'] ?? ''));
            if ($code === '') {
                continue;
            }
            if ($spr === '41') {
                ArmTariff::updateOrCreate(
                    ['code' => $code],
                    ['name' => $name ?: $code, 'rate' => (float) $code]
                );
                $tariffs++;
            } elseif ($spr === '50') {
                ArmPayKind::updateOrCreate(
                    ['code' => $code],
                    ['name' => $name ?: $code]
                );
                $kinds++;
            }
        }

        return $tariffs + $kinds;
    }

    public function importSecondPersonHours(string $dir): int
    {
        $count = 0;
        foreach ($this->chas2Files($dir) as $file) {
            $count += $this->importChas2File($file);
        }

        return $count;
    }

    private function importChas2File(string $file): int
    {
        $personnel = ArmPersonnel::query()->pluck('user_id', 'tab_number');
        $count = 0;
        foreach ((new DbfReader($file))->records() as $row) {
            $tab = trim((string) ($row['TABNOM'] ?? ''));
            $date = $row['DAT'] ?? null;
            if ($tab === '' || ! $date || empty($personnel[$tab])) {
                continue;
            }
            $assignment = NaryadAssignment::query()
                ->where('user_id', $personnel[$tab])
                ->whereDate('plan_date', $date)
                ->first();
            if (! $assignment) {
                continue;
            }
            $payload = $this->mapSecondHours($row);
            if ($payload === []) {
                continue;
            }
            $payload['two_person'] = true;
            $assignment->fill($payload);
            $assignment->save();
            $count++;
        }

        return $count;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, float>
     */
    private function mapSecondHours(array $row): array
    {
        if (array_key_exists('LIN2', $row)) {
            return [
                'hours_line_2' => (float) ($row['LIN2'] ?? 0),
                'hours_night_2' => (float) ($row['NOCH2'] ?? 0),
                'hours_evening_2' => (float) ($row['VECH2'] ?? 0),
                'hours_break_2' => (float) ($row['RAZR2'] ?? 0),
                'hours_holiday_2' => (float) ($row['PRAZD2'] ?? 0),
                'hours_line_2_reserve' => (float) ($row['LIN2P'] ?? 0),
                'hours_night_2_reserve' => (float) ($row['NOCH2P'] ?? 0),
                'hours_evening_2_reserve' => (float) ($row['VECH2P'] ?? 0),
                'hours_break_2_reserve' => (float) ($row['RAZR2P'] ?? 0),
                'hours_holiday_2_reserve' => (float) ($row['PRAZD2P'] ?? 0),
            ];
        }

        $hours = [
            'hours_line_2' => (float) ($row['LIN1'] ?? 0),
            'hours_reserve_2' => (float) ($row['CHASR1'] ?? 0),
            'hours_night_2' => (float) ($row['NOCH1'] ?? 0),
            'hours_night_2_reserve' => (float) ($row['NOCHR1'] ?? 0),
            'hours_evening_2' => (float) ($row['VECH1'] ?? 0),
            'hours_evening_2_reserve' => (float) ($row['VECHR1'] ?? 0),
            'hours_break_2' => (float) ($row['RAZR1'] ?? 0),
            'hours_break_2_reserve' => (float) ($row['RAZRR1'] ?? 0),
            'hours_holiday_2' => (float) ($row['PRAZD1'] ?? 0),
            'hours_holiday_2_reserve' => (float) ($row['PRAZDR1'] ?? 0),
            'hours_total_2' => (float) ($row['CHAS'] ?? 0),
        ];
        if (array_sum($hours) == 0.0) {
            return [];
        }

        return $hours;
    }

    /**
     * @return list<string>
     */
    private function chas2Files(string $dir): array
    {
        $out = [];
        foreach ([
            $dir.DIRECTORY_SEPARATOR.'CHAS2.DBF',
            dirname($dir).DIRECTORY_SEPARATOR.'REZCHET'.DIRECTORY_SEPARATOR.'CHAS2.DBF',
        ] as $path) {
            if (is_file($path)) {
                $out[] = $path;
            }
        }
        foreach (glob($dir.DIRECTORY_SEPARATOR.'C2*.DBF') ?: [] as $path) {
            $out[] = $path;
        }
        foreach (glob($dir.DIRECTORY_SEPARATOR.'c2*.dbf') ?: [] as $path) {
            $out[] = $path;
        }

        return array_values(array_unique($out));
    }

    private function findDbf(string $dir, string $stem): ?string
    {
        foreach (glob($dir.DIRECTORY_SEPARATOR.$stem.'.DBF') ?: [] as $path) {
            return $path;
        }
        foreach (glob($dir.DIRECTORY_SEPARATOR.$stem.'.dbf') ?: [] as $path) {
            return $path;
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $chunk
     */
    private function upsertExtraPays(array $chunk): void
    {
        ArmExtraPay::upsert(
            $chunk,
            ['year_month', 'tab_number'],
            [
                'user_id', 'full_name', 'position_code',
                'hours_tech', 'tech_on', 'hours_accident', 'accident_on',
                'hours_med', 'med_on', 'extra_days_off', 'extra_hours_off',
                'updated_at',
            ]
        );
    }

    /**
     * @param  list<array<string, mixed>>  $chunk
     */
    private function upsertMonthPremiums(array $chunk): void
    {
        ArmMonthPremium::upsert(
            $chunk,
            ['year_month', 'tab_number', 'position_code'],
            ['user_id', 'percent_plan', 'percent_fact', 'ktu', 'note', 'updated_at']
        );
    }

    private function isPlus(mixed $value): bool
    {
        return trim((string) $value) === '+';
    }

    private function nullable(mixed $value): ?string
    {
        $s = trim((string) $value);

        return $s === '' ? null : $s;
    }
}
