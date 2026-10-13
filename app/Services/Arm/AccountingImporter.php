<?php

namespace App\Services\Arm;

use App\Models\ArmCode;
use App\Models\ArmCodeBook;
use App\Models\ArmExtraPay;
use App\Models\ArmMonthNorm;
use App\Models\ArmMonthPremium;
use App\Models\ArmPayFormula;
use App\Models\ArmPayKind;
use App\Models\ArmPersonnel;
use App\Models\ArmPremiumRate;
use App\Models\ArmRoster;
use App\Models\ArmSeniorityBand;
use App\Models\ArmSignature;
use App\Models\ArmTariff;
use App\Models\NaryadAssignment;
use App\Models\NaryadNorm;

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
                $payload = [
                    'name' => trim((string) ($row['NAZV'] ?? '')) ?: 'строка '.$nom,
                    'percent_text' => $this->nullable($row['PROC'] ?? null),
                    'percent_source' => $this->nullable($row['FPROC'] ?? null),
                    'percent' => (float) ($row['PROCR'] ?? $row['PROC'] ?? 100),
                    'pay_code' => $this->nullable($row['VIDOPL'] ?? null),
                    'tariff_code' => $this->nullable($row['TARIF'] ?? null),
                    'tariff' => $this->formulaTariff($row),
                    'cost_code' => $this->nullable($row['SHZAT'] ?? null),
                    'formula' => $formula,
                ];
                if (array_key_exists('OTBOR', $row)) {
                    $payload['selected'] = $this->isSelected($row['OTBOR']);
                } else {
                    $payload['selected'] = true;
                }
                ArmPayFormula::updateOrCreate(
                    ['kind' => $kind, 'nom' => $nom],
                    $payload
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
        $this->importCodeBooks($dir);

        return $this->importCodes($dir);
    }

    public function importCodeBooks(string $dir): int
    {
        $file = $this->findDbf($dir, 'KODIF');
        if (! $file) {
            return 0;
        }
        $count = 0;
        foreach ((new DbfReader($file))->records() as $row) {
            $kodspr = trim((string) ($row['KODSPR'] ?? ''));
            if ($kodspr === '') {
                continue;
            }
            ArmCodeBook::updateOrCreate(
                ['kodspr' => $kodspr],
                [
                    'dbfspr' => trim((string) ($row['DBFSPR'] ?? '')) ?: 'ELKODIF',
                    'nsisr' => $this->nullable($row['NSISR'] ?? null),
                    'nazspr' => trim((string) ($row['NAZSPR'] ?? '')) ?: $kodspr,
                    'dlkodel' => (int) ($row['DLKODEL'] ?? 2),
                    'dlnazel' => (int) ($row['DLNAZEL'] ?? 30),
                ]
            );
            $count++;
        }

        return $count;
    }

    public function importCodes(string $dir): int
    {
        $file = $this->findDbf($dir, 'ELKODIF');
        if (! $file) {
            return 0;
        }
        $count = 0;
        foreach ((new DbfReader($file))->records() as $row) {
            $spr = trim((string) ($row['KODSPR'] ?? ''));
            $code = trim((string) ($row['KODEL'] ?? ''));
            $name = trim((string) ($row['NAZEL'] ?? ''));
            $nom = trim((string) ($row['NOM'] ?? ''));
            if ($spr === '' || $code === '') {
                continue;
            }
            ArmCodeBook::firstOrCreate(
                ['kodspr' => $spr],
                [
                    'dbfspr' => 'ELKODIF',
                    'nazspr' => $spr,
                    'dlkodel' => 8,
                    'dlnazel' => 60,
                ]
            );
            $record = ArmCode::updateOrCreate(
                ['book_code' => $spr, 'code' => $code, 'nom' => $nom],
                ['name' => $name]
            );
            $record->syncSpecialized();
            $count++;
        }

        return $count;
    }

    public function importSignatures(string $dir): int
    {
        $file = $this->findDbf($dir, 'PODPISI');
        if (! $file) {
            return 0;
        }
        $count = 0;
        foreach ((new DbfReader($file))->records() as $row) {
            $position = trim((string) ($row['DOLZN'] ?? ''));
            $name = trim((string) ($row['FIO'] ?? ''));
            if ($position === '' && $name === '') {
                continue;
            }
            ArmSignature::updateOrCreate(
                [
                    'position' => $position,
                    'full_name' => $name,
                ],
                ['sort_order' => (int) ($row['OTBOR'] ?? 0)]
            );
            $count++;
        }

        return $count;
    }

    public function importRosters(string $dir): int
    {
        $count = 0;
        $map = [
            'RASST' => ArmRoster::KIND_PRIMARY,
            'RASST1' => ArmRoster::KIND_EXTRA,
        ];
        foreach ($map as $stem => $kind) {
            $file = $this->findDbf($dir, $stem);
            if (! $file) {
                continue;
            }
            foreach ((new DbfReader($file))->records() as $row) {
                $date = $row['DAT'] ?? null;
                $route = trim((string) ($row['NM'] ?? ''));
                if (! is_string($date) || $date === '' || $route === '') {
                    continue;
                }
                $instrument = trim((string) ($row['INSTR'] ?? ''));
                ArmRoster::updateOrCreate(
                    [
                        'roster_date' => $date,
                        'kind' => $kind,
                        'route_number' => $route,
                    ],
                    ['instrument' => $instrument === '' ? null : $instrument]
                );
                $count++;
            }
        }

        return $count;
    }

    public function importNrdpar(string $dir): int
    {
        $file = $this->findDbf($dir, 'NRDPAR')
            ?? $this->findDbf(dirname($dir).DIRECTORY_SEPARATOR.'NRDPAR', 'NRDPAR')
            ?? $this->findDbf(dirname($dir), 'NRDPAR');
        if (! $file) {
            return 0;
        }
        $row = null;
        foreach ((new DbfReader($file))->records() as $record) {
            $row = $record;
            break;
        }
        if (! $row) {
            return 0;
        }

        $norm = NaryadNorm::firstOrCreate([], [
            'year_hours' => 2000,
            'month_hours' => 166,
            'week_hours' => 48,
            'min_rest_hours' => 8,
        ]);
        $incoming = $this->nrdparToPlanir($row);
        $incomingPrint = is_array($incoming['print'] ?? null) ? $incoming['print'] : null;
        unset($incoming['print']);
        $merged = array_replace(app(PlanirRulesService::class)->settings($norm), $incoming);
        $merged['night_shift_codes'] = $this->keepNightFivePlus($merged['night_shift_codes'] ?? []);
        if ($incomingPrint !== null) {
            $merged['print'] = array_replace(
                is_array($merged['print'] ?? null) ? $merged['print'] : [],
                $incomingPrint
            );
        }
        $norm->planir = $merged;
        $norm->save();

        return 1;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function nrdparToPlanir(array $row): array
    {
        $nr12 = trim((string) ($row['NR_12'] ?? ''));
        $mapped = [
            'interval_mode' => str_starts_with($nr12, '+') ? 'fixed' : 'formula',
            'interval_hours' => 12,
            'rest_day_hours' => (float) ($row['NR_1VIH'] ?? 42),
            'two_rest_days_hours' => (float) ($row['NR_2VIH'] ?? 66),
            'min_days_between_rests' => (int) ($row['NR_MINV'] ?? 3),
            'max_days_without_rest' => (int) ($row['NR_NOVIH'] ?? 12),
            'short_interval_hours' => (float) ($row['NR_KINT'] ?? 0),
            'norm_extra_hours' => (float) ($row['NR_DOPPR'] ?? 40),
            'subtract_holiday_hours' => trim((string) ($row['NR_PRAZD'] ?? '-')) === '-',
            'line_mode' => (int) ($row['NR_LINIA'] ?? 1),
            'route_from' => (int) ($row['NR_NMN'] ?? 1),
            'route_to' => (int) ($row['NR_NMK'] ?? 35),
            'org' => trim((string) ($row['NR_ORG'] ?? '')),
            'chief' => trim((string) ($row['NR_NACH'] ?? '')),
            'deputy_ops' => trim((string) ($row['NR_ZEKS'] ?? '')),
            'union_chair' => trim((string) ($row['NR_PROF'] ?? '')),
            'evening_from' => (float) ($row['NR_VECH1'] ?? 0),
            'evening_to' => (float) ($row['NR_VECH2'] ?? 0),
            'night_from' => (float) ($row['NR_NOCH1'] ?? 0),
            'night_to' => (float) ($row['NR_NOCH2'] ?? 0),
            'advance' => trim((string) ($row['NR_AVANS'] ?? '')),
            'tech_hours' => (float) ($row['NR_TEH'] ?? 0),
            'ktu' => trim((string) ($row['NR_KTU'] ?? '')),
            'compat_pay_code' => trim((string) ($row['NR_VOPLS'] ?? '')),
            'tea_sum' => (float) ($row['NR_SCHAI'] ?? 0),
            'light_metro' => trim((string) ($row['NR_CEHLM'] ?? '')),
            'merge_night' => trim((string) ($row['NR_ONOCH'] ?? '')),
            'train_count' => (int) ($row['NR_VERT'] ?? 0),
            'absence_as_rest' => trim((string) ($row['NR_VIHOTP'] ?? '')),
            'install_place' => trim((string) ($row['NR_ORDE'] ?? '')),
            'dde' => trim((string) ($row['NR_DDE'] ?? '')),
        ];

        $nights = ['3+', '4+', '5+'];
        $countNights = [];
        foreach (['NR_NSM1', 'NR_NSM2'] as $field) {
            $code = trim((string) ($row[$field] ?? ''));
            if ($code !== '') {
                $nights[] = $code;
                $countNights[] = $code;
            }
        }
        $mapped['night_shift_codes'] = $this->keepNightFivePlus($nights);
        $mapped['night_count_codes'] = $countNights !== []
            ? array_values(array_unique($countNights))
            : ['3+', '4+'];

        $ran1 = (float) ($row['NR_2RAN1'] ?? 0);
        $ran2 = (float) ($row['NR_2RAN2'] ?? 0);
        if ($ran1 > 0 && $ran2 > 0) {
            $mapped['early_from'] = $ran1;
            $mapped['early_to'] = $ran2;
        }

        $brig = trim((string) ($row['NR_BRIG'] ?? ''));
        if ($brig !== '' && is_numeric($brig)) {
            $mapped['brigadier_percent'] = (float) $brig;
        }
        if (array_key_exists('NR_P1L', $row)) {
            $mapped['premium_p1l'] = (bool) $row['NR_P1L'];
        }

        $print = [];
        if (array_key_exists('NR_PVAG', $row)) {
            $print['show_wagons'] = trim((string) $row['NR_PVAG']) === '+';
        }
        if (array_key_exists('NR_POK1', $row)) {
            $print['show_end_on_line'] = trim((string) $row['NR_POK1']) === '+';
        }
        if (array_key_exists('NR_POK2', $row)) {
            $print['show_end_off_line'] = trim((string) $row['NR_POK2']) === '+';
        }
        if (array_key_exists('NR_NAZGR', $row)) {
            $print['show_graph_name'] = trim((string) $row['NR_NAZGR']) === '+';
        }
        if (array_key_exists('NR_DINF', $row)) {
            $print['extra_text_prompt'] = trim((string) $row['NR_DINF']) === '+';
        }
        if (array_key_exists('NR_OTST', $row)) {
            $print['note_indent'] = (int) $row['NR_OTST'];
        }
        if (array_key_exists('NR_VERT', $row)) {
            $print['merge_extra_roster'] = (int) $row['NR_VERT'] === 2;
        }
        if (array_key_exists('NR_ZAGM', $row)) {
            $print['header_banner'] = trim((string) $row['NR_ZAGM']);
        }
        if ($print !== []) {
            $mapped['print'] = $print;
        }

        return $mapped;
    }

    /**
     * @param  mixed  $codes
     * @return list<string>
     */
    public function keepNightFivePlus(mixed $codes): array
    {
        if (is_string($codes)) {
            $codes = array_map('trim', explode(',', $codes));
        }
        if (! is_array($codes)) {
            $codes = [];
        }
        $out = [];
        foreach ($codes as $code) {
            $code = trim((string) $code);
            if ($code !== '') {
                $out[] = $code;
            }
        }
        if (! in_array('5+', $out, true)) {
            $out[] = '5+';
        }

        return array_values(array_unique($out));
    }

    /**
     * @param  list<string>  $tabs
     */
    public function importSecondPersonHours(string $dir, ?string $yearMonth = null, array $tabs = []): int
    {
        $count = 0;
        foreach ($this->chas2Files($dir) as $file) {
            $count += $this->importChas2File($file, $yearMonth, $tabs);
        }

        return $count;
    }

    /**
     * @param  list<string>  $tabs
     */
    public function importNaryad(string $dir, ?string $yearMonth = null, array $tabs = []): int
    {
        $count = 0;
        foreach ($this->naryadFiles($dir, $yearMonth) as $file) {
            $count += $this->importNaryadFile($file, $yearMonth, $tabs);
        }

        return $count;
    }

    /**
     * @param  list<string>  $tabs
     */
    private function importNaryadFile(string $file, ?string $yearMonth, array $tabs): int
    {
        $personnel = ArmPersonnel::query()->pluck('user_id', 'tab_number');
        $want = $tabs === [] ? null : array_fill_keys($tabs, true);
        $chunk = [];
        $count = 0;
        $now = now();

        foreach ((new DbfReader($file))->records() as $row) {
            $tab = trim((string) ($row['TABNOM'] ?? ''));
            $date = $row['DAT'] ?? null;
            if ($tab === '' || ! $date || empty($personnel[$tab])) {
                continue;
            }
            if ($want !== null && ! isset($want[$tab])) {
                continue;
            }
            if ($yearMonth && ! str_starts_with((string) $date, $yearMonth)) {
                continue;
            }
            $work = trim((string) ($row['WORK'] ?? $row['NM'] ?? ''));
            $shift = trim((string) ($row['SMENA'] ?? $row['SM'] ?? ''));
            $otbor = trim((string) ($row['OTBOR'] ?? ''));
            $chunk[] = [
                'user_id' => $personnel[$tab],
                'plan_date' => $date,
                'route_number' => $work !== '' ? $work : ($shift !== '' ? $shift : '.'),
                'work_code' => $work !== '' ? $work : null,
                'shift_code' => $shift !== '' ? $shift : null,
                'mark_code' => $otbor !== '' ? $otbor : null,
                'registered' => $otbor !== '',
                'hours_total' => (float) ($row['CHAS'] ?? 0),
                'hours_line' => (float) ($row['LIN1'] ?? 0),
                'hours_reserve' => (float) ($row['CHASR1'] ?? 0),
                'hours_night' => (float) ($row['NOCH1'] ?? 0),
                'hours_night_reserve' => (float) ($row['NOCHR1'] ?? 0),
                'hours_evening' => (float) ($row['VECH1'] ?? 0),
                'hours_evening_reserve' => (float) ($row['VECHR1'] ?? 0),
                'hours_break' => (float) ($row['RAZR1'] ?? 0),
                'hours_break_reserve' => (float) ($row['RAZRR1'] ?? 0),
                'hours_holiday' => (float) ($row['PRAZD1'] ?? 0),
                'hours_holiday_reserve' => (float) ($row['PRAZDR1'] ?? 0),
                'hours_overtime' => (float) ($row['PERERAB'] ?? 0),
                'two_person' => trim((string) ($row['REZM2L'] ?? '')) !== '',
                'created_at' => $now,
                'updated_at' => $now,
            ];
            if (count($chunk) >= 200) {
                $this->upsertNaryad($chunk);
                $count += count($chunk);
                $chunk = [];
            }
        }
        if ($chunk !== []) {
            $this->upsertNaryad($chunk);
            $count += count($chunk);
        }

        return $count;
    }

    /**
     * @param  list<array<string, mixed>>  $chunk
     */
    private function upsertNaryad(array $chunk): void
    {
        NaryadAssignment::upsert(
            $chunk,
            ['user_id', 'plan_date'],
            [
                'route_number', 'work_code', 'shift_code', 'mark_code', 'registered',
                'hours_total', 'hours_line', 'hours_reserve',
                'hours_night', 'hours_night_reserve',
                'hours_evening', 'hours_evening_reserve',
                'hours_break', 'hours_break_reserve',
                'hours_holiday', 'hours_holiday_reserve',
                'hours_overtime', 'two_person', 'updated_at',
            ]
        );
    }

    private function importChas2File(string $file, ?string $yearMonth = null, array $tabs = []): int
    {
        $personnel = ArmPersonnel::query()->pluck('user_id', 'tab_number');
        $want = $tabs === [] ? null : array_fill_keys($tabs, true);
        $count = 0;
        foreach ((new DbfReader($file))->records() as $row) {
            $tab = trim((string) ($row['TABNOM'] ?? ''));
            $date = $row['DAT'] ?? null;
            if ($tab === '' || ! $date || empty($personnel[$tab])) {
                continue;
            }
            if ($want !== null && ! isset($want[$tab])) {
                continue;
            }
            if ($yearMonth && ! str_starts_with((string) $date, $yearMonth)) {
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
    private function naryadFiles(string $dir, ?string $yearMonth): array
    {
        $out = [];
        $this->pushDbf($out, $dir.DIRECTORY_SEPARATOR.'NARJAD.DBF');
        foreach (glob($dir.DIRECTORY_SEPARATOR.'NR*.DBF') ?: [] as $path) {
            $base = basename((string) $path);
            if (preg_match('/NRCHAS/i', $base)) {
                continue;
            }
            if (! preg_match('/^NR(\d{2})(\d{2})\.DBF$/i', $base, $m)) {
                continue;
            }
            if ($yearMonth) {
                $yy = (int) $m[2];
                $fileMonth = sprintf('%04d-%s', $yy >= 50 ? 1900 + $yy : 2000 + $yy, $m[1]);
                if ($fileMonth !== $yearMonth) {
                    continue;
                }
            }
            $this->pushDbf($out, $path);
        }

        return array_values($out);
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

    private function isSelected(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        $s = trim((string) $value);

        return $s !== '' && $s !== '0' && ! in_array(strtoupper($s), ['F', 'N', '-'], true);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function formulaTariff(array $row): float
    {
        $nTariff = (float) ($row['N_TARIF'] ?? 0);
        if (abs($nTariff) > 0.0000001) {
            return $nTariff;
        }

        return (float) ($row['CHTAR'] ?? 0);
    }

    private function nullable(mixed $value): ?string
    {
        $s = trim((string) $value);

        return $s === '' ? null : $s;
    }
}
