<?php

namespace App\Services\Arm;

use App\Models\ArmPayFormula;
use App\Models\ArmPersonnel;
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

    private function nullable(mixed $value): ?string
    {
        $s = trim((string) $value);

        return $s === '' ? null : $s;
    }
}
