<?php

namespace App\Services\Arm;

use App\Models\ArmAppointment;
use App\Models\ArmExtraPay;
use App\Models\ArmHoliday;
use App\Models\ArmMonthNorm;
use App\Models\ArmMonthPremium;
use App\Models\ArmPersonnel;
use App\Models\ArmPremiumRate;
use App\Models\ArmSeniorityBand;
use App\Models\NaryadAssignment;
use App\Models\NaryadNorm;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Итоги ЛС как RAS1LS.PRG: часы нарядов + TEHUCH/SPPREM/VISL/NRCHAS/PREM.
 */
class LsTotalsCalculator
{
    /**
     * @param  iterable<NaryadAssignment>  $assignments
     * @return array<string, float>
     */
    public function calculate(?ArmPersonnel $personnel, string $yearMonth, iterable $assignments): array
    {
        $start = Carbon::createFromFormat('Y-m', $yearMonth)->startOfMonth();
        $end = $start->copy()->endOfMonth();
        $personnel ??= new ArmPersonnel([
            'position_code' => 'МШ',
            'tab_number' => '',
            'full_name' => '',
        ]);
        $position = trim((string) ($personnel->position_code ?: 'МШ'));
        $isAssistant = $this->isAssistant($position);
        $planir = $this->planir();
        $rows = $assignments instanceof Collection ? $assignments : collect($assignments);

        $t = $this->emptyTotals();
        $allchas = 0.0;
        $vchasr2 = 0.0;
        $nochr2 = 0.0;
        $vechr2 = 0.0;
        $razrr2 = 0.0;
        $prazdr2 = 0.0;

        foreach ($rows as $row) {
            $line = (float) $row->hours_line;
            $reserve = (float) $row->hours_reserve;
            $line2 = (float) $row->hours_line_2;
            $line2p = (float) $row->hours_line_2_reserve;
            $isRest = $this->isRestRow($row, $planir['rest_codes']);
            $hasShift = $this->isWorkShift($row, $isRest);
            $allchas += $line + $reserve + $line2 + $line2p;

            if ($hasShift) {
                $t['vchas1'] += $line;
                $t['vchasr'] += $reserve;
                $t['noch1'] += (float) $row->hours_night;
                $t['nochr'] += (float) $row->hours_night_reserve;
                $t['vech1'] += (float) $row->hours_evening;
                $t['vechr'] += (float) $row->hours_evening_reserve;
                $t['razr1'] += (float) $row->hours_break;
                $t['razrr'] += (float) $row->hours_break_reserve;
                $t['prazd1'] += (float) $row->hours_holiday;
                $t['prazdr'] += (float) $row->hours_holiday_reserve;
                $t['dni'] += 1.0;
                if ($this->isNightShift($row, $planir['night_shift_codes'])) {
                    $t['kn'] += 1.0;
                }
                if ($row->two_person) {
                    $vchasr2 += $reserve;
                    $nochr2 += (float) $row->hours_night_reserve;
                    $vechr2 += (float) $row->hours_evening_reserve;
                    $razrr2 += (float) $row->hours_break_reserve;
                    $prazdr2 += (float) $row->hours_holiday_reserve;
                }
            } elseif ($isRest) {
                $t['vyh'] += 1.0;
            }

            $t['vchas2'] += $line2;
            $t['noch2'] += (float) $row->hours_night_2;
            $t['vech2'] += (float) $row->hours_evening_2;
            $t['razr2'] += (float) $row->hours_break_2;
            $t['prazd2'] += (float) $row->hours_holiday_2;
            $t['vchas2p'] += $line2p;
            $t['noch2p'] += (float) $row->hours_night_2_reserve;
            $t['vech2p'] += (float) $row->hours_evening_2_reserve;
            $t['razr2p'] += (float) $row->hours_break_2_reserve;
            $t['prazd2p'] += (float) $row->hours_holiday_2_reserve;
        }

        $t['vchasr2'] = $vchasr2;
        $t['vchasr'] -= $vchasr2;
        $t['nochr'] -= $nochr2;
        $t['vechr'] -= $vechr2;
        $t['razrr'] -= $razrr2;
        $t['prazdr'] -= $prazdr2;
        $t['prazdr2'] = $prazdr2;

        if ($isAssistant) {
            $t['vchasrp'] = $t['vchasr'];
            $t['vchasr'] = 0.0;
            $t['prazdrp'] = $t['prazdr'];
            $t['prazdr'] = 0.0;
            $t['nochrp'] = $t['nochr'];
            $t['nochr'] = 0.0;
            $t['vechrp'] = $t['vechr'];
            $t['vechr'] = 0.0;
            $t['razrrp'] = $t['razrr'];
            $t['razrr'] = 0.0;
        }

        $t['vc1'] = $t['vchas1'] - $t['prazd1'];
        $t['vc2'] = $t['vchas2'] - $t['prazd2'];
        $t['vcr'] = $t['vchasr'] - $t['prazdr'];
        $t['vcr2'] = $t['vchasr2'] - $t['prazdr2'];
        $t['vc2p'] = $t['vchas2p'] - $t['prazd2p'];
        $t['vcrp'] = $t['vchasrp'] - $t['prazdrp'];

        $norm = ArmMonthNorm::query()->where('year_month', $yearMonth)->first();
        $tNr = (float) ($norm?->month_hours ?? 0);
        $dNr = (float) ($norm?->day_hours ?? 0);
        $tekNr = $this->monthNormHours($personnel, $start, $end, $tNr, $dNr);
        $t['tek_nr'] = $tekNr;
        $t['mes_nr'] = $tekNr;
        $t['t_nr'] = $tNr;
        $t['t_dnnr'] = $dNr;

        $extra = $this->extraPay($personnel, $yearMonth);
        $t['nvih'] = $this->nvihHours($extra, $position, $dNr);
        $this->fillTechMed($t, $extra, $personnel, $start, $end, $position);

        $nehv = $tekNr - ($allchas - $t['prazd1'] - $t['prazd2'] - $t['prazdr'] - $t['prazdr2'] - $t['prazd2p'] - $t['prazdrp'] - $t['nvih']);
        if ($nehv > 0) {
            $t['dobnv'] = min($t['nvih'], $nehv);
            $nehv -= $t['dobnv'];
        }
        if ($nehv > 0) {
            $t['dob1'] = min($t['prazd1'], $nehv);
            $nehv -= $t['dob1'];
        }
        if ($nehv > 0) {
            $t['dobr'] = min($t['prazdr'], $nehv);
            $nehv -= $t['dobr'];
        }
        if ($nehv > 0) {
            $t['dob2'] = min($t['prazd2'], $nehv);
            $nehv -= $t['dob2'];
        }
        if ($nehv > 0) {
            $t['dobr2'] = min($t['prazdr2'], $nehv);
            $nehv -= $t['dobr2'];
        }
        if ($nehv > 0) {
            $t['dob2p'] = min($t['prazd2p'], $nehv);
            $nehv -= $t['dob2p'];
        }
        if ($nehv > 0) {
            $t['dobrp'] = min($t['prazdrp'], $nehv);
        }

        $this->fillClassHours($t, $personnel, $isAssistant, $start, $end, $rows);
        $this->fillOvertime($t, $personnel, $allchas, $planir);
        $this->fillSeniority($t, $personnel, $start, $allchas, $tekNr, $planir);
        $this->fillPremium($t, $personnel, $yearMonth, $position, $planir);
        $this->fillBrigadier($t, $personnel, $start, $end, $rows, $planir);

        $t['allchas'] = $allchas;
        foreach ($t as $key => $value) {
            $t[$key] = round((float) $value, 4);
        }

        return $t;
    }

    /**
     * @return array<string, float>
     */
    public function emptyTotals(): array
    {
        return [
            'vchas1' => 0.0, 'vc1' => 0.0,
            'vchas2' => 0.0, 'vc2' => 0.0,
            'vchasr' => 0.0, 'vcr' => 0.0,
            'vchasr2' => 0.0, 'vcr2' => 0.0,
            'vchas2p' => 0.0, 'vc2p' => 0.0,
            'vchasrp' => 0.0, 'vcrp' => 0.0,
            'noch1' => 0.0, 'noch2' => 0.0, 'nochr' => 0.0, 'noch2p' => 0.0, 'nochrp' => 0.0,
            'razr1' => 0.0, 'razr2' => 0.0, 'razrr' => 0.0, 'razr2p' => 0.0, 'razrrp' => 0.0,
            'vech1' => 0.0, 'vech2' => 0.0, 'vechr' => 0.0, 'vech2p' => 0.0, 'vechrp' => 0.0,
            'prazd1' => 0.0, 'prazd2' => 0.0, 'prazdr' => 0.0, 'prazdr2' => 0.0, 'prazd2p' => 0.0, 'prazdrp' => 0.0,
            'per1' => 0.0, 'realper' => 0.0, 'nvih' => 0.0, 'dobnv' => 0.0,
            'dob1' => 0.0, 'dob2' => 0.0, 'dobr' => 0.0, 'dobr2' => 0.0, 'dob2p' => 0.0, 'dobrp' => 0.0,
            'teh' => 0.0, 'medk' => 0.0, 'avar' => 0.0,
            'premm' => 0.0, 'premp' => 0.0, 'prem2' => 0.0, 'prem' => 0.0,
            'visl' => 0.0, 'vislp' => 0.0,
            'kl1' => 0.0, 'kl1l2' => 0.0, 'kl2' => 0.0, 'kl2l2' => 0.0, 'kl3' => 0.0, 'kl3l2' => 0.0,
            'klp1' => 0.0, 'klp1r' => 0.0,
            'brig' => 0.0, 'brigp' => 0.0,
            'tek_nr' => 0.0, 'mes_nr' => 0.0, 't_nr' => 0.0, 't_dnnr' => 0.0,
            'dni' => 0.0, 'vyh' => 0.0, 'kn' => 0.0, 'allchas' => 0.0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function planir(): array
    {
        $norm = NaryadNorm::query()->first();
        $stored = is_array($norm?->planir) ? $norm->planir : [];

        return array_replace([
            'night_shift_codes' => ['3+', '4+', '5+'],
            'rest_codes' => ['В', 'Е', 'ВЫХ'],
            'brigadier_percent' => 10,
            'shop_combined' => '11',
            'shop_dde' => '',
            'premium_p1l' => false,
        ], $stored);
    }

    private function isAssistant(string $position): bool
    {
        return str_contains($position, 'П');
    }

    /**
     * @param  list<string>  $nightCodes
     */
    private function isNightShift(NaryadAssignment $row, array $nightCodes): bool
    {
        $shift = (string) $row->shift_code;
        foreach ($nightCodes as $code) {
            if ($shift !== '' && (string) $code === $shift) {
                return true;
            }
        }

        return mb_stripos((string) $row->route_number, 'ночь') !== false
            && ! str_contains((string) $row->route_number, '1-с');
    }

    private function isWorkShift(NaryadAssignment $row, bool $isRest): bool
    {
        if (filled($row->shift_code)) {
            return true;
        }
        $hours = (float) $row->hours_line + (float) $row->hours_reserve
            + (float) $row->hours_line_2 + (float) $row->hours_line_2_reserve;

        return $hours > 0 && ! $isRest;
    }

    /**
     * @param  list<string>  $restCodes
     */
    private function isRestRow(NaryadAssignment $row, array $restCodes): bool
    {
        $work = mb_strtoupper(trim((string) ($row->work_code ?: $row->route_number)));
        if ($work === '') {
            return false;
        }
        foreach ($restCodes as $code) {
            $code = mb_strtoupper(trim((string) $code));
            if ($code !== '' && ($work === $code || str_starts_with($work, $code))) {
                return true;
            }
        }

        return false;
    }

    private function monthNormHours(ArmPersonnel $personnel, CarbonInterface $start, CarbonInterface $end, float $tNr, float $dNr): float
    {
        $hired = $personnel->hired_on;
        $fired = $personnel->fired_on;
        $hireIn = $hired && $hired->between($start, $end);
        $fireIn = $fired && $fired->between($start, $end);
        if (! $hireIn && ! $fireIn) {
            return $tNr;
        }
        $from = $hired ? $hired->copy()->max($start) : $start->copy();
        $to = $fired ? $fired->copy()->min($end) : $end->copy();
        $koldn = $this->workDays($from, $to);
        $tek = $koldn * $dNr;
        if ($tNr > 0 && $tek > $tNr) {
            return $tNr;
        }

        return $tek;
    }

    private function workDays(CarbonInterface $from, CarbonInterface $to): int
    {
        $holidays = ArmHoliday::query()
            ->whereDate('holiday_date', '>=', $from->toDateString())
            ->whereDate('holiday_date', '<=', $to->toDateString())
            ->get()
            ->map(fn ($h) => $h->holiday_date?->toDateString())
            ->filter()
            ->all();
        $count = 0;
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            if ($d->isSunday()) {
                continue;
            }
            if (in_array($d->toDateString(), $holidays, true)) {
                continue;
            }
            $count++;
        }

        return $count;
    }

    private function extraPay(ArmPersonnel $personnel, string $yearMonth): ?ArmExtraPay
    {
        $tab = trim((string) $personnel->tab_number);
        if ($tab === '' && ! $personnel->user_id) {
            return null;
        }

        return ArmExtraPay::query()
            ->where('year_month', $yearMonth)
            ->where(function ($q) use ($personnel, $tab) {
                if ($tab !== '') {
                    $q->where('tab_number', $tab);
                }
                if ($personnel->user_id) {
                    $q->orWhere('user_id', $personnel->user_id);
                }
            })
            ->first();
    }

    private function nvihHours(?ArmExtraPay $extra, string $position, float $dNr): float
    {
        if (! $extra) {
            return 0.0;
        }
        $extraPos = trim((string) $extra->position_code);
        if ($extraPos !== '' && $extraPos !== $position) {
            return 0.0;
        }
        if ((float) $extra->extra_hours_off > 0) {
            return (float) $extra->extra_hours_off;
        }

        return ((int) $extra->extra_days_off) * $dNr;
    }

    /**
     * @param  array<string, float>  $t
     */
    private function fillTechMed(array &$t, ?ArmExtraPay $extra, ArmPersonnel $personnel, CarbonInterface $start, CarbonInterface $end, string $position): void
    {
        if (! $extra) {
            return;
        }
        $appointment = null;
        if ($personnel->user_id || $personnel->tab_number) {
            $query = ArmAppointment::query()
                ->whereDate('appointed_on', '>=', $start->toDateString())
                ->whereDate('appointed_on', '<=', $end->toDateString());
            if ($personnel->user_id) {
                $query->where('user_id', $personnel->user_id);
            } else {
                $query->where('tab_number', $personnel->tab_number);
            }
            $appointment = $query->orderBy('appointed_on')->first();
        }
        if (! $appointment) {
            $t['teh'] = (float) $extra->hours_tech;
            $t['avar'] = (float) $extra->hours_accident;
            $t['medk'] = (float) $extra->hours_med;

            return;
        }
        $newPos = trim((string) $appointment->position_code);
        $t['teh'] = $this->techField($extra->tech_on, (float) $extra->hours_tech, $start, $end, $position, $newPos);
        $t['avar'] = $this->techField($extra->accident_on, (float) $extra->hours_accident, $start, $end, $position, $newPos);
        $t['medk'] = $this->techField($extra->med_on, (float) $extra->hours_med, $start, $end, $position, $newPos);
    }

    private function techField(?CarbonInterface $on, float $hours, CarbonInterface $start, CarbonInterface $end, string $lsPosition, string $appointmentPosition): float
    {
        if ($on) {
            return $on->between($start, $end) ? $hours : 0.0;
        }

        return $lsPosition === $appointmentPosition ? $hours : 0.0;
    }

    /**
     * @param  array<string, float>  $t
     * @param  Collection<int, NaryadAssignment>  $rows
     */
    private function fillClassHours(array &$t, ArmPersonnel $personnel, bool $isAssistant, CarbonInterface $start, CarbonInterface $end, Collection $rows): void
    {
        $otrab = $t['vchas1'] + $t['vchas2'] + $t['vchasr'] + $t['vchasr2'] + $t['vchas2p'] + $t['vchasrp'];
        if ($otrab == 0.0) {
            return;
        }
        $klass = trim((string) ($personnel->class_code ?? ''));
        $change = $this->classChange($personnel, $start, $end, $klass);
        if ($change === null) {
            $this->applyClassBucket($t, $klass, $isAssistant, $t['vchas1'] + $t['vchasr'], $t['vchas2'] + $t['vchasr2'], $t['vchas2p'], $t['vchasrp']);

            return;
        }
        [$oldClass, $on] = $change;
        $oldLine = 0.0;
        $oldRes = 0.0;
        $old2 = 0.0;
        $old2p = 0.0;
        $newLine = 0.0;
        $newRes = 0.0;
        $new2 = 0.0;
        $new2p = 0.0;
        foreach ($rows as $row) {
            if (! $this->isWorkShift($row, false)) {
                continue;
            }
            $date = $row->plan_date instanceof CarbonInterface ? $row->plan_date : Carbon::parse($row->plan_date);
            $before = $date->lt($on);
            if ($before) {
                $oldLine += (float) $row->hours_line;
                $oldRes += (float) $row->hours_reserve;
                $old2 += (float) $row->hours_line_2;
                $old2p += (float) $row->hours_line_2_reserve;
            } else {
                $newLine += (float) $row->hours_line;
                $newRes += (float) $row->hours_reserve;
                $new2 += (float) $row->hours_line_2;
                $new2p += (float) $row->hours_line_2_reserve;
            }
        }
        $this->applyClassBucket($t, $oldClass, $isAssistant, $oldLine + $oldRes, $old2, $old2p, $isAssistant ? $oldRes : 0.0);
        $this->applyClassBucket($t, $klass, $isAssistant, $newLine + $newRes, $new2, $new2p, $isAssistant ? $newRes : 0.0);
    }

    /**
     * @return array{0: string, 1: CarbonInterface}|null
     */
    private function classChange(ArmPersonnel $personnel, CarbonInterface $start, CarbonInterface $end, string $currentClass): ?array
    {
        $query = ArmAppointment::query()
            ->whereDate('appointed_on', '>=', $start->toDateString())
            ->whereDate('appointed_on', '<=', $end->toDateString())
            ->orderBy('appointed_on');
        if ($personnel->user_id) {
            $query->where('user_id', $personnel->user_id);
        } elseif ($personnel->tab_number) {
            $query->where('tab_number', $personnel->tab_number);
        } else {
            return null;
        }
        $inMonth = $query->get();
        if ($inMonth->isEmpty()) {
            return null;
        }
        $first = $inMonth->first();
        $prev = ArmAppointment::query()
            ->whereDate('appointed_on', '<', $first->appointed_on->toDateString())
            ->when($personnel->user_id, fn ($q) => $q->where('user_id', $personnel->user_id))
            ->when(! $personnel->user_id && $personnel->tab_number, fn ($q) => $q->where('tab_number', $personnel->tab_number))
            ->orderByDesc('appointed_on')
            ->first();
        $oldClass = trim((string) ($prev?->class_code ?? ''));
        $newClass = trim((string) ($first->class_code ?? $currentClass));
        $samePos = trim((string) $first->position_code) === trim((string) ($personnel->position_code ?? ''));
        if ($oldClass === '' || $oldClass === $newClass || ! $samePos) {
            return null;
        }

        return [$oldClass, $first->appointed_on];
    }

    /**
     * @param  array<string, float>  $t
     */
    private function applyClassBucket(array &$t, string $klass, bool $isAssistant, float $linePlusRes, float $second, float $secondPom, float $resPom): void
    {
        if ($klass === '1' && $isAssistant) {
            $t['klp1'] += $secondPom;
            $t['klp1r'] += $resPom;

            return;
        }
        if ($klass === '1') {
            $t['kl1'] += $linePlusRes;
            $t['kl1l2'] += $second;

            return;
        }
        if ($klass === '2' && ! $isAssistant) {
            $t['kl2'] += $linePlusRes;
            $t['kl2l2'] += $second;

            return;
        }
        if ($klass === '3' && ! $isAssistant) {
            $t['kl3'] += $linePlusRes;
            $t['kl3l2'] += $second;
        }
    }

    /**
     * @param  array<string, float>  $t
     * @param  array<string, mixed>  $planir
     */
    private function fillOvertime(array &$t, ArmPersonnel $personnel, float $allchas, array $planir): void
    {
        $shop = trim((string) ($personnel->shop_code ?? ''));
        $combined = trim((string) $planir['shop_combined']);
        $dde = trim((string) $planir['shop_dde']);
        if (($combined !== '' && $shop === $combined) || ($dde !== '' && $shop === $dde)) {
            $t['per1'] = 0.0;
            $t['realper'] = 0.0;

            return;
        }
        $real = $allchas - $t['mes_nr'];
        if ($real < 0) {
            $real = 0.0;
        }
        $t['realper'] = $real;
        $per = $real - $t['prazd1'] - $t['prazdr'] - $t['prazd2'] - $t['prazdr2'] - $t['prazd2p'] - $t['prazdrp'] - $t['nvih'];
        $t['per1'] = $per <= 0.01 ? 0.0 : $per;
    }

    /**
     * @param  array<string, float>  $t
     * @param  array<string, mixed>  $planir
     */
    private function fillSeniority(array &$t, ArmPersonnel $personnel, CarbonInterface $start, float $allchas, float $normTls, array $planir): void
    {
        $shop = trim((string) ($personnel->shop_code ?? ''));
        $combined = trim((string) $planir['shop_combined']);
        $dde = trim((string) $planir['shop_dde']);
        if (($combined !== '' && $shop === $combined) || ($dde !== '' && $shop === $dde) || ! $personnel->seniority_on) {
            return;
        }
        $from = $personnel->seniority_on;
        $klet = intdiv(
            (12 * ($start->year - 1) + $start->month) - (12 * ($from->year - 1) + $from->month),
            12
        );
        $band = ArmSeniorityBand::query()
            ->where('years_from', '<=', $klet)
            ->where('years_to', '>=', $klet)
            ->orderBy('years_from')
            ->first();
        $t['vislp'] = (float) ($band?->percent ?? 0);
        if ($t['vislp'] <= 0) {
            return;
        }
        $lineHours = $t['vchas1'] + $t['vchas2'] + $t['vchasr'] + $t['vchasr2'] + $t['vchas2p'] + $t['vchasrp'];
        if ($lineHours == 0.0) {
            $t['visl'] = 0.0;

            return;
        }
        $vis = $allchas <= $normTls
            ? $lineHours
            : $normTls - ($allchas - $lineHours);
        $t['visl'] = min($vis, $normTls);
    }

    /**
     * @param  array<string, float>  $t
     * @param  array<string, mixed>  $planir
     */
    private function fillPremium(array &$t, ArmPersonnel $personnel, string $yearMonth, string $position, array $planir): void
    {
        $row = ArmMonthPremium::query()
            ->where('year_month', $yearMonth)
            ->where('tab_number', $personnel->tab_number)
            ->where(function ($q) use ($position) {
                $q->where('position_code', $position)->orWhereNull('position_code')->orWhere('position_code', '');
            })
            ->orderByRaw('case when position_code = ? then 0 else 1 end', [$position])
            ->first();
        if (! $row && $position === 'МШ') {
            $row = ArmMonthPremium::query()
                ->where('year_month', $yearMonth)
                ->where('tab_number', $personnel->tab_number)
                ->where('position_code', 'М2')
                ->first();
        }
        if (! $row) {
            return;
        }
        $fact = (float) $row->percent_fact;
        $p1l = (bool) $planir['premium_p1l'];
        if ($position === 'МШ') {
            $t['premm'] = $fact;
        } elseif ($this->isAssistant($position)) {
            $hasMash = $t['vchas1'] != 0.0 || $t['vchas2'] != 0.0 || $t['vchasr'] != 0.0 || $t['vchasr2'] != 0.0;
            $onlyPom = ! $hasMash && ($t['vchas2p'] != 0.0 || $t['vchasrp'] != 0.0);
            if ($hasMash) {
                $pmPos = $p1l ? 'М2' : 'МШ';
                $pm = (float) (ArmPremiumRate::query()
                    ->where('position_code', $pmPos)
                    ->where('is_brigadier', false)
                    ->value('percent') ?? 0);
                $pp = (float) (ArmPremiumRate::query()
                    ->where('position_code', 'П/М')
                    ->where('is_brigadier', false)
                    ->value('percent') ?? 0);
                $izm = $pp - $fact;
                $t['premp'] = $fact;
                $t['premm'] = max($pm - $izm, 0);
                if ($t['vchas1'] != 0.0) {
                    if ($t['vchas2'] != 0.0) {
                        $t['prem2'] = $t['premm'];
                    }
                    $mash = (float) (ArmPremiumRate::query()
                        ->where('position_code', 'МШ')
                        ->where('is_brigadier', false)
                        ->value('percent') ?? 0);
                    $t['premm'] = $mash > 0 ? $mash - $izm : 0.0;
                }
            } elseif ($onlyPom) {
                $t['premp'] = $fact;
            }
        }
        if ($p1l) {
            if ($t['vchas2'] != 0.0 || $t['vchasr2'] != 0.0) {
                $m2 = ArmMonthPremium::query()
                    ->where('year_month', $yearMonth)
                    ->where('tab_number', $personnel->tab_number)
                    ->where('position_code', 'М2')
                    ->first();
                if ($m2) {
                    $t['prem2'] = (float) $m2->percent_fact;
                }
            }
            if ($t['vchas1'] == 0.0 && $t['vchasr'] == 0.0) {
                if ($this->isAssistant($position)) {
                    $t['prem2'] = $t['premm'];
                }
                $t['premm'] = 0.0;
            }
        }
        $t['prem'] = $this->isAssistant($position) ? $t['premp'] : $t['premm'];
    }

    /**
     * @param  array<string, float>  $t
     * @param  Collection<int, NaryadAssignment>  $rows
     * @param  array<string, mixed>  $planir
     */
    private function fillBrigadier(array &$t, ArmPersonnel $personnel, CarbonInterface $start, CarbonInterface $end, Collection $rows, array $planir): void
    {
        $from = $personnel->brigadier_from;
        $to = $personnel->brigadier_to;
        $percent = (float) $planir['brigadier_percent'];
        $fullHours = $t['vchas1'] + $t['vchas2'] + $t['vchasr'] + $t['vchasr2'] + $t['vchas2p'] + $t['vchasrp'];
        if ($from || $to) {
            $coversAll = $from && $from->lte($start) && (! $to || $to->gte($end));
            $overlaps = ($from && $from->between($start, $end))
                || ($to && $to->between($start, $end))
                || $coversAll;
            if (! $overlaps) {
                return;
            }
            $t['brigp'] = $percent;
            if ($coversAll) {
                $t['brig'] = $fullHours;

                return;
            }
            $dn = $from ? $from->copy()->max($start) : $start->copy();
            $dk = $to ? $to->copy()->min($end) : $end->copy();
            $sum = 0.0;
            foreach ($rows as $row) {
                if (! $this->isWorkShift($row, false)) {
                    continue;
                }
                $date = $row->plan_date instanceof CarbonInterface ? $row->plan_date : Carbon::parse($row->plan_date);
                if ($date->between($dn, $dk)) {
                    $sum += (float) $row->hours_line + (float) $row->hours_reserve
                        + (float) $row->hours_line_2 + (float) $row->hours_line_2_reserve;
                }
            }
            $t['brig'] = $sum;

            return;
        }
        if ($personnel->is_brigadier) {
            $t['brigp'] = $percent;
            $t['brig'] = $fullHours;
        }
    }
}
