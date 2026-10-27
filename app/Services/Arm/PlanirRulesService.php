<?php

namespace App\Services\Arm;

use App\Models\ArmAbsence;
use App\Models\ArmAppointment;
use App\Models\ArmDayAdjustment;
use App\Models\ArmHoliday;
use App\Models\ArmMonthNorm;
use App\Models\ArmPeriod;
use App\Models\ArmPersonnel;
use App\Models\DeviationsCatalog;
use App\Models\NaryadAssignment;
use App\Models\NaryadNorm;
use App\Services\Naryad\NaryadHoursCalculator;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class PlanirRulesService
{
    public function __construct(
        private NaryadHoursCalculator $hours,
        private ShiftHoursService $shiftHours,
    ) {}

    /**
     * @return list<string>
     */
    public function validateAssign(int $userId, CarbonInterface $date, string $routeKey, NaryadNorm $norm): array
    {
        $errors = [];
        $yearMonth = $date->format('Y-m');
        if (ArmPeriod::closedMonth($yearMonth)) {
            $errors[] = 'Месяц '.$yearMonth.' закрыт. Назначения менять нельзя.';

            return $errors;
        }

        $planir = $this->settings($norm);
        $deviationNames = DeviationsCatalog::query()->pluck('name')->all();
        $restCodes = $planir['rest_codes'];
        $isDeviation = in_array($routeKey, $deviationNames, true);
        $isRest = $this->isRestKey($routeKey, $deviationNames, $restCodes);
        $shiftCode = $this->shiftHours->parseRouteKey($routeKey)[1];
        $isNight = $this->isNight($routeKey, $shiftCode, $planir['night_shift_codes']);
        $isFirst = $this->isFirstShift($shiftCode, $routeKey);

        $errors = array_merge($errors, $this->checkHours($userId, $date, $routeKey, $norm, $planir, $deviationNames, $isDeviation || $isRest));
        $errors = array_merge($errors, $this->checkExtras($userId, $date, $routeKey, $norm, $deviationNames, $isDeviation || $isRest, $isNight));

        if (! $isRest && ! $isDeviation) {
            $absence = ArmAbsence::query()
                ->where('user_id', $userId)
                ->whereDate('starts_on', '<=', $date->toDateString())
                ->whereDate('ends_on', '>=', $date->toDateString())
                ->first();
            if ($absence) {
                $errors[] = 'На '.$date->format('d.m.Y').' есть отвлечение '.$absence->kind_code
                    .' ('.$absence->starts_on->format('d.m.Y').'–'.$absence->ends_on->format('d.m.Y').')';
            }
        }

        $adjustment = ArmDayAdjustment::query()
            ->where('user_id', $userId)
            ->whereDate('plan_date', $date->toDateString())
            ->first();
        if ($adjustment && ! $isRest && ! $isDeviation) {
            [$routeCode, $parsedShift] = $this->shiftHours->parseRouteKey($routeKey);
            $needShift = $adjustment->shift_code;
            $shiftOk = $needShift === '' || $parsedShift === $needShift || ltrim($parsedShift, '0') === ltrim($needShift, '0');
            if ($routeCode !== $adjustment->route_code || ! $shiftOk) {
                $errors[] = 'Назначение не соответствует подстройке на '.$date->format('d.m.Y')
                    .': нужно '.$adjustment->route_code.'.'.$adjustment->shift_code;
            }
        }

        $byDate = $this->assignmentsAround($userId, $date);
        $prev = $byDate->get($date->copy()->subDay()->toDateString());
        $prevKey = $prev?->route_number;
        $prevShift = $prev ? $this->shiftHours->parseRouteKey((string) $prevKey)[1] : '';
        $prevRest = $prevKey ? $this->isRestKey((string) $prevKey, $deviationNames, $restCodes) : false;
        $prevNight = $prevKey ? $this->isNight((string) $prevKey, $prevShift, $planir['night_shift_codes']) : false;
        $prevFirst = $prevKey ? $this->isFirstShift($prevShift, (string) $prevKey) : false;

        $isNightContinuation = $this->isContinuationOfNight($prev, $routeKey);
        if ($planir['enforce_after_night'] && $prevNight && ! $isFirst && ! $isNightContinuation && ! $isRest && ! $isDeviation) {
            $errors[] = 'После ночной смены должна быть 1-я ('.$date->format('d.m.Y').')';
        }
        if ($planir['enforce_after_first'] && $prevFirst && ! $isRest && ! $isDeviation) {
            $errors[] = 'После 1-й смены должен быть выходной ('.$date->format('d.m.Y').')';
        }

        $errors = array_merge($errors, $this->checkInterval(
            $date,
            $routeKey,
            $prev,
            $byDate,
            $planir,
            $deviationNames,
            $isRest || $isDeviation,
            $isFirst || $isNightContinuation
        ));
        $errors = array_merge($errors, $this->checkRestSpacing($date, $isRest, $byDate, $planir, $deviationNames));
        $errors = array_merge($errors, $this->checkDaysWithoutRest($date, $isRest || $isDeviation, $byDate, $planir, $deviationNames));
        $errors = array_merge($errors, $this->checkConsecutivePatterns($date, $routeKey, $isRest || $isDeviation, $byDate, $planir));
        $errors = array_merge($errors, $this->checkPersonnelWindows($userId, $date, $routeKey, $isFirst, $shiftCode));
        if (! $isRest && ! $isDeviation) {
            $errors = array_merge($errors, $this->checkCrew($userId, $date, $routeKey, $planir));
        }

        return array_values(array_filter($errors));
    }

    /**
     * @return array<string, mixed>
     */
    public function settings(?NaryadNorm $norm): array
    {
        $defaults = [
            'interval_mode' => 'formula',
            'interval_hours' => 12,
            'rest_day_hours' => 42,
            'two_rest_days_hours' => 66,
            'min_days_between_rests' => 3,
            'max_days_without_rest' => 12,
            'early_from' => 5.0,
            'early_to' => 8.0,
            'night_shift_codes' => ['3+', '4+', '5+'],
            'night_count_codes' => ['3+', '4+'],
            'rest_codes' => ['В', 'Е', 'ВЫХ'],
            'enforce_after_first' => true,
            'enforce_after_night' => true,
            'short_interval_hours' => 0.0,
            'max_short_intervals' => 3,
            'norm_extra_hours' => 40.0,
            'subtract_holiday_hours' => true,
            'line_mode' => 1,
            'route_from' => 1,
            'route_to' => 35,
            'shop_combined' => '11',
            'own_depo' => 'ТЧ15',
            'dde' => '',
        ];
        $stored = is_array($norm?->planir) ? $norm->planir : [];

        return array_replace($defaults, $stored);
    }

    /**
     * @param  list<string>  $deviationNames
     * @param  list<string>  $restCodes
     */
    public function isRestKey(string $routeKey, array $deviationNames, array $restCodes): bool
    {
        if (in_array($routeKey, $deviationNames, true)) {
            return true;
        }
        $upper = mb_strtoupper(trim($routeKey));
        foreach ($restCodes as $code) {
            $code = mb_strtoupper(trim((string) $code));
            if ($code !== '' && (str_starts_with($upper, $code) || $upper === $code)) {
                return true;
            }
        }
        $short = DeviationsCatalog::query()->whereNotNull('short_code')->pluck('short_code')->all();
        foreach ($short as $code) {
            $code = mb_strtoupper(trim((string) $code));
            if ($code !== '' && ($upper === $code || str_starts_with($upper, $code))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $nightCodes
     */
    public function isNight(string $routeKey, string $shiftCode, array $nightCodes): bool
    {
        if (mb_stripos($routeKey, 'ночь') !== false && ! str_contains($routeKey, '1-с')) {
            return true;
        }
        foreach ($nightCodes as $code) {
            if ($shiftCode !== '' && (string) $code === $shiftCode) {
                return true;
            }
        }

        return str_contains($shiftCode, '+');
    }

    public function isFirstShift(string $shiftCode, string $routeKey): bool
    {
        return $shiftCode === '1' || str_starts_with($routeKey, '1 ') || str_contains($routeKey, '(1-');
    }

    /**
     * @param  array<string, mixed>  $planir
     * @param  list<string>  $deviationNames
     * @return list<string>
     */
    private function checkHours(int $userId, CarbonInterface $date, string $routeKey, NaryadNorm $norm, array $planir, array $deviationNames, bool $skipWorkHours): array
    {
        $estimated = $this->shiftHours->hoursForRouteOnDate($date, $routeKey)
            ?? $this->hours->getHoursFromRouteKey($routeKey, $deviationNames);
        $errors = [];

        $weekStart = $date->copy()->startOfWeek(Carbon::MONDAY);
        $weekEnd = $weekStart->copy()->endOfWeek(Carbon::SUNDAY);
        $week = $this->hours->getUserPlannedWorkHours($userId, $weekStart, $weekEnd, $date->toDateString(), $estimated, $skipWorkHours);
        if ($week > $norm->week_hours) {
            $errors[] = "Превышен лимит часов в неделю: {$week} > {$norm->week_hours}";
        }

        $monthKey = $date->format('Y-m');
        $monthLimit = $norm->monthly_hours[$monthKey] ?? $norm->month_hours;
        $month = $this->hours->getUserPlannedWorkHours(
            $userId,
            $date->copy()->startOfMonth(),
            $date->copy()->endOfMonth(),
            $date->toDateString(),
            $estimated,
            $skipWorkHours
        );
        if ($month > $monthLimit) {
            $errors[] = "Превышен лимит часов в месяц ({$monthKey}): {$month} > {$monthLimit}";
        }

        $year = $this->hours->getUserPlannedWorkHours(
            $userId,
            $date->copy()->startOfYear(),
            $date->copy()->endOfYear(),
            $date->toDateString(),
            $estimated,
            $skipWorkHours
        );
        if ($year > $norm->year_hours) {
            $errors[] = "Превышен лимит часов в год: {$year} > {$norm->year_hours}";
        }

        if (! $skipWorkHours) {
            $errors = array_merge($errors, $this->checkNrchasCeiling(
                $userId,
                $date,
                $routeKey,
                $month,
                $planir
            ));
        }

        return $errors;
    }

    /**
     * Норма NRCHAS: dnnr × рабочие дни (без воскресений и PRAZD) + NR_doppr.
     * Нет строки NRCHAS — потолок 0 + doppr (как FoxPro t_norm=0).
     *
     * @param  array<string, mixed>  $planir
     * @return list<string>
     */
    private function checkNrchasCeiling(int $userId, CarbonInterface $date, string $routeKey, float $monthHours, array $planir): array
    {
        $row = ArmMonthNorm::query()->where('year_month', $date->format('Y-m'))->first();
        $dnnr = $row ? (float) $row->day_hours : 0.0;
        $workingDays = $this->workingDaysUntil($date);
        $normHours = round($workingDays * $dnnr, 2);
        $extra = (float) $planir['norm_extra_hours'];
        $sum = $monthHours;
        if ($planir['subtract_holiday_hours']) {
            $sum -= $this->holidayHoursInMonth($userId, $date, $routeKey);
        }
        $sum = round($sum, 2);
        $limit = round($normHours + $extra, 2);
        if ($sum > $limit) {
            return ['Превышена норма часов NRCHAS на '.$date->format('d.m.Y').': '.$sum.' > '.$limit];
        }

        return [];
    }

    private function workingDaysUntil(CarbonInterface $date): int
    {
        $holidays = ArmHoliday::query()
            ->whereBetween('holiday_date', [$date->copy()->startOfMonth()->toDateString(), $date->toDateString()])
            ->pluck('holiday_date')
            ->map(fn ($d) => Carbon::parse($d)->toDateString())
            ->all();
        $count = 0;
        $cursor = $date->copy()->startOfMonth();
        while ($cursor->lte($date)) {
            $isSunday = $cursor->dayOfWeek === Carbon::SUNDAY;
            $isHoliday = in_array($cursor->toDateString(), $holidays, true);
            if (! $isSunday && ! $isHoliday) {
                $count++;
            }
            $cursor->addDay();
        }

        return $count;
    }

    private function holidayHoursInMonth(int $userId, CarbonInterface $date, string $routeKey): float
    {
        $sum = (float) NaryadAssignment::query()
            ->where('user_id', $userId)
            ->whereBetween('plan_date', [$date->copy()->startOfMonth()->toDateString(), $date->copy()->endOfMonth()->toDateString()])
            ->whereDate('plan_date', '!=', $date->toDateString())
            ->get()
            ->sum(fn (NaryadAssignment $a) => (float) $a->hours_holiday + (float) $a->hours_holiday_reserve);

        return $sum + $this->shiftHours->holidayHoursForRouteOnDate($date, $routeKey);
    }

    /**
     * @param  list<string>  $deviationNames
     * @return list<string>
     */
    private function checkExtras(int $userId, CarbonInterface $date, string $routeKey, NaryadNorm $norm, array $deviationNames, bool $isRest, bool $isNight): array
    {
        $errors = [];
        foreach ($norm->extraConditions()->where('is_active', true)->get() as $extra) {
            $val = (int) $extra->value;
            if ($extra->name === 'max_days_in_row' && ! $isRest) {
                $streak = 1;
                $check = $date->copy()->subDay();
                while (true) {
                    $prev = NaryadAssignment::query()
                        ->where('user_id', $userId)
                        ->whereDate('plan_date', $check->toDateString())
                        ->first();
                    if (! $prev || $this->isRestKey((string) $prev->route_number, $deviationNames, $this->settings($norm)['rest_codes'])) {
                        break;
                    }
                    $streak++;
                    if ($streak > $val) {
                        $errors[] = "Превышен макс. дней подряд: {$streak} > {$val}";
                        break;
                    }
                    $check->subDay();
                }
            } elseif ($extra->name === 'max_night_shifts') {
                $from = $date->copy()->subDays(30);
                $nightCount = NaryadAssignment::query()
                    ->where('user_id', $userId)
                    ->whereBetween('plan_date', [$from->toDateString(), $date->toDateString()])
                    ->get()
                    ->filter(function ($a) {
                        $shift = app(ShiftHoursService::class)->parseRouteKey((string) $a->route_number)[1];

                        return $this->isNight((string) $a->route_number, $shift, ['3+', '4+', '5+']);
                    })->count();
                if ($isNight) {
                    $nightCount++;
                }
                if ($nightCount > $val) {
                    $errors[] = "Превышен макс. ночных смен: {$nightCount} > {$val}";
                }
            }
        }

        return $errors;
    }

    /**
     * @param  Collection<string, NaryadAssignment>  $byDate
     * @param  array<string, mixed>  $planir
     * @param  list<string>  $deviationNames
     * @return list<string>
     */
    private function checkInterval(
        CarbonInterface $date,
        string $routeKey,
        ?NaryadAssignment $prev,
        Collection $byDate,
        array $planir,
        array $deviationNames,
        bool $isRest,
        bool $skipInterval
    ): array {
        if ($isRest) {
            return [];
        }
        $errors = [];
        $thisBd = $this->shiftHours->findForRouteOnDate($date, $routeKey);
        $workPrev = $prev;
        $restStreak = 0;
        $cursor = $date->copy()->subDay();
        while ($workPrev && $this->isRestKey((string) $workPrev->route_number, $deviationNames, $planir['rest_codes'])) {
            $restStreak++;
            $cursor->subDay();
            $workPrev = $byDate->get($cursor->toDateString());
        }

        if (! $skipInterval && $thisBd && ! ((float) $thisBd->start_hours === 0.0 && (float) $thisBd->end_hours === 0.0) && $workPrev) {
            $prevBd = $this->shiftHours->findForRouteOnDate($workPrev->plan_date, (string) $workPrev->route_number);
            if ($prevBd) {
                $prevEnd = FoxTime::endAt($workPrev->plan_date, $prevBd->start_hours, $prevBd->end_hours);
                $thisStart = FoxTime::at($date, $thisBd->start_hours);
                $delta = FoxTime::hoursBetween($prevEnd, $thisStart);
                $chas = $this->previousDutyHours($workPrev, $byDate, $planir);
                $need = $this->requiredIntervalHours($chas, $restStreak, $planir);
                if ($delta < $need) {
                    $label = $restStreak >= 2 ? 'двух выходных' : ($restStreak >= 1 ? 'выходного' : 'смен');
                    $errors[] = 'Интервал '.$label.' '.$date->format('d.m.Y').': '.$delta.' ч < '.$need;
                }
            }
        }

        if (! $skipInterval) {
            $errors = array_merge($errors, $this->checkShortIntervals($date, $routeKey, $thisBd, $byDate, $planir, $deviationNames));
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $planir
     */
    public function requiredIntervalHours(float $chas, int $restStreak, array $planir): float
    {
        if (($planir['interval_mode'] ?? 'formula') === 'fixed') {
            $need = (float) $planir['interval_hours'];
            if ($restStreak >= 1) {
                $need = (float) $planir['rest_day_hours'];
            }
            if ($restStreak >= 2) {
                $need = (float) $planir['two_rest_days_hours'];
            }

            return $need;
        }
        if ($restStreak < 1) {
            return max((float) $planir['interval_hours'], $chas * 2);
        }
        $n = $restStreak;

        return max(18 + $n * 24, $chas * 2 + $n * 24);
    }

    /**
     * @param  Collection<string, NaryadAssignment>  $byDate
     * @param  array<string, mixed>  $planir
     */
    private function previousDutyHours(NaryadAssignment $workPrev, Collection $byDate, array $planir): float
    {
        $hours = $this->shiftHours->hoursForRouteOnDate($workPrev->plan_date, (string) $workPrev->route_number);
        if ($hours === null) {
            $hours = (float) ($workPrev->hours_total ?? 0);
        }
        $prevKey = (string) $workPrev->route_number;
        $prevShift = $this->shiftHours->parseRouteKey($prevKey)[1];
        $nightAss = $byDate->get($workPrev->plan_date->copy()->subDay()->toDateString());
        $fromNight = $this->isFirstShift($prevShift, $prevKey) || $this->isContinuationOfNight($nightAss, $prevKey);
        if ($fromNight && $nightAss) {
            $nightShift = $this->shiftHours->parseRouteKey((string) $nightAss->route_number)[1];
            if ($this->isNight((string) $nightAss->route_number, $nightShift, $planir['night_shift_codes'])) {
                $nightHours = $this->shiftHours->hoursForRouteOnDate($nightAss->plan_date, (string) $nightAss->route_number);
                $hours += $nightHours ?? (float) ($nightAss->hours_total ?? 0);
            }
        }

        return (float) $hours;
    }

    /**
     * В месяце не больше max_short_intervals интервалов короче NR_kint.
     *
     * @param  Collection<string, NaryadAssignment>  $byDate
     * @param  array<string, mixed>  $planir
     * @param  list<string>  $deviationNames
     * @return list<string>
     */
    private function checkShortIntervals(
        CarbonInterface $date,
        string $routeKey,
        mixed $thisBd,
        Collection $byDate,
        array $planir,
        array $deviationNames
    ): array {
        $kint = (float) $planir['short_interval_hours'];
        if ($kint <= 0) {
            return [];
        }
        $max = (int) $planir['max_short_intervals'];
        $count = 0;
        $cursor = $date->copy()->startOfMonth();
        while ($cursor->lte($date)) {
            $isCurrent = $cursor->isSameDay($date);
            $cur = $isCurrent ? null : $byDate->get($cursor->toDateString());
            $curKey = $isCurrent ? $routeKey : (string) ($cur?->route_number ?? '');
            $curBd = $isCurrent ? $thisBd : ($cur ? $this->shiftHours->findForRouteOnDate($cur->plan_date, $curKey) : null);
            if ($curKey === '' || $this->isRestKey($curKey, $deviationNames, $planir['rest_codes'])) {
                $cursor->addDay();

                continue;
            }
            $curShift = $this->shiftHours->parseRouteKey($curKey)[1];
            if ($this->isFirstShift($curShift, $curKey)) {
                $cursor->addDay();

                continue;
            }
            $prev = $byDate->get($cursor->copy()->subDay()->toDateString());
            if (! $prev || $this->isRestKey((string) $prev->route_number, $deviationNames, $planir['rest_codes'])) {
                $cursor->addDay();

                continue;
            }
            $prevBd = $this->shiftHours->findForRouteOnDate($prev->plan_date, (string) $prev->route_number);
            if (! $prevBd || ! $curBd) {
                $cursor->addDay();

                continue;
            }
            $delta = FoxTime::hoursBetween(
                FoxTime::endAt($prev->plan_date, $prevBd->start_hours, $prevBd->end_hours),
                FoxTime::at($cursor, $curBd->start_hours)
            );
            if ($delta > 0 && $delta < $kint) {
                $count++;
            }
            $cursor->addDay();
        }
        if ($count > $max) {
            return ['Более '.$max.' межсменных интервалов короче '.$kint.' ч в месяце ('.$date->format('d.m.Y').')'];
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $planir
     * @return list<string>
     */
    private function checkCrew(int $userId, CarbonInterface $date, string $routeKey, array $planir): array
    {
        [$routeCode, $shiftCode] = $this->shiftHours->parseRouteKey($routeKey);
        if ($routeCode === '') {
            return [];
        }
        $others = NaryadAssignment::query()
            ->whereDate('plan_date', $date->toDateString())
            ->where('user_id', '!=', $userId)
            ->get()
            ->filter(function (NaryadAssignment $row) use ($routeCode, $shiftCode) {
                [$otherRoute, $otherShift] = $this->shiftHours->parseRouteKey((string) $row->route_number);
                if ($otherRoute !== $routeCode) {
                    return false;
                }
                if ($shiftCode === '' || $otherShift === '') {
                    return $shiftCode === $otherShift;
                }

                return $shiftCode === $otherShift || ltrim($shiftCode, '0') === ltrim($otherShift, '0');
            })
            ->values();

        $lineMode = (int) $planir['line_mode'];
        $allowed = $lineMode === 2 ? 2 : 1;
        if ($others->count() >= $allowed) {
            return ['На маршрут '.$routeCode.($shiftCode !== '' ? '.'.$shiftCode : '').' на '.$date->format('d.m.Y').' уже назначено '.$allowed.' чел.'];
        }
        if ($lineMode !== 2 || $others->count() !== 1) {
            return [];
        }

        return $this->checkInexperiencedPair($userId, (int) $others[0]->user_id, $date, $routeCode, $shiftCode);
    }

    /**
     * @return list<string>
     */
    private function checkInexperiencedPair(int $userId, int $otherId, CarbonInterface $date, string $routeCode, string $shiftCode): array
    {
        $self = $this->positionOn($userId, $date);
        $other = $this->positionOn($otherId, $date);
        $errors = [];
        $label = $routeCode.($shiftCode !== '' ? '.'.$shiftCode : '');
        if ($self['days'] < 365 && $other['days'] < 365) {
            $errors[] = 'На смену '.$label.' оба со стажем менее 1 года ('.$date->format('d.m.Y').')';
        }
        $selfPm = $this->isAssistantPosition($self['position']);
        $otherPm = $this->isAssistantPosition($other['position']);
        if ($selfPm && $otherPm && (! $this->isFirstClass($self['class']) || ! $this->isFirstClass($other['class']))) {
            $errors[] = 'На смену '.$label.' оба П/М; нужны 1 класса ('.$date->format('d.m.Y').')';
        }

        return $errors;
    }

    /**
     * @return array{position: string, class: string, days: int}
     */
    private function positionOn(int $userId, CarbonInterface $date): array
    {
        $appt = ArmAppointment::query()
            ->where('user_id', $userId)
            ->whereDate('appointed_on', '<=', $date->toDateString())
            ->orderByDesc('appointed_on')
            ->first();
        $person = ArmPersonnel::query()->where('user_id', $userId)->first();
        $appointed = $appt?->appointed_on ?? $person?->hired_on ?? $person?->seniority_on;
        $days = $appointed ? $appointed->diffInDays($date) : 9999;

        return [
            'position' => trim((string) ($appt?->position_code ?? $person?->position_code ?? '')),
            'class' => trim((string) ($appt?->class_code ?? $person?->class_code ?? '')),
            'days' => (int) $days,
        ];
    }

    private function isAssistantPosition(string $position): bool
    {
        $upper = mb_strtoupper(trim($position));

        return $upper === 'П/М' || $upper === 'П' || str_contains($upper, 'П/М');
    }

    private function isFirstClass(string $class): bool
    {
        return ltrim(trim($class), '0') === '1';
    }

    private function isContinuationOfNight(?NaryadAssignment $prev, string $routeKey): bool
    {
        if (! $prev) {
            return false;
        }
        $from = $this->shiftHours->fromNightOf((string) $prev->route_number);
        if ($from === null) {
            return false;
        }
        [$thisRoute] = $this->shiftHours->parseRouteKey($routeKey);

        return $thisRoute !== '' && $thisRoute === $from;
    }

    /**
     * @param  Collection<string, NaryadAssignment>  $byDate
     * @param  array<string, mixed>  $planir
     * @param  list<string>  $deviationNames
     * @return list<string>
     */
    private function checkRestSpacing(CarbonInterface $date, bool $isRest, Collection $byDate, array $planir, array $deviationNames): array
    {
        if (! $isRest) {
            return [];
        }
        $gap = (int) $planir['min_days_between_rests'];
        for ($i = 2; $i <= $gap; $i++) {
            $prev = $byDate->get($date->copy()->subDays($i)->toDateString());
            if ($prev && $this->isRestKey((string) $prev->route_number, $deviationNames, $planir['rest_codes'])) {
                $adjacent = $byDate->get($date->copy()->subDay()->toDateString());
                if ($adjacent && $this->isRestKey((string) $adjacent->route_number, $deviationNames, $planir['rest_codes'])) {
                    return [];
                }

                return ['Не соблюдается '.$gap.'-дневный интервал между выходными ('.$date->format('d.m.Y').')'];
            }
        }

        return [];
    }

    /**
     * @param  Collection<string, NaryadAssignment>  $byDate
     * @param  array<string, mixed>  $planir
     * @param  list<string>  $deviationNames
     * @return list<string>
     */
    private function checkDaysWithoutRest(CarbonInterface $date, bool $isRest, Collection $byDate, array $planir, array $deviationNames): array
    {
        if ($isRest) {
            return [];
        }
        $max = (int) $planir['max_days_without_rest'];
        $streak = 1;
        for ($i = 1; $i <= $max; $i++) {
            $prev = $byDate->get($date->copy()->subDays($i)->toDateString());
            if (! $prev || $this->isRestKey((string) $prev->route_number, $deviationNames, $planir['rest_codes'])) {
                return [];
            }
            $streak++;
        }
        if ($streak > $max) {
            return ['Работа более '.$max.' дней без выходных ('.$date->format('d.m.Y').')'];
        }

        return [];
    }

    /**
     * @param  Collection<string, NaryadAssignment>  $byDate
     * @param  array<string, mixed>  $planir
     * @return list<string>
     */
    private function checkConsecutivePatterns(CarbonInterface $date, string $routeKey, bool $isRest, Collection $byDate, array $planir): array
    {
        if ($isRest) {
            return [];
        }
        $thisBd = $this->shiftHours->findForRouteOnDate($date, $routeKey);
        $errors = [];
        $earlyStreak = $this->streakMatching($date, $routeKey, $byDate, function (?object $bd) use ($planir) {
            if (! $bd) {
                return false;
            }
            $start = (float) $bd->start_hours;

            return $start >= (float) $planir['early_from'] && $start <= (float) $planir['early_to'];
        }, $thisBd);
        if ($earlyStreak >= 3) {
            $errors[] = 'Назначены ранние смены подряд ('.$date->format('d.m.Y').')';
        }

        $oddStreak = $this->streakMatching($date, $routeKey, $byDate, function (?object $bd) {
            if (! $bd) {
                return false;
            }
            $startMin = FoxTime::toMinutes($bd->start_hours);
            $endMin = FoxTime::toMinutes($bd->end_hours);

            return ($startMin >= 0 && $startMin <= 5 * 60) || ($endMin >= 0 && $endMin <= 5 * 60);
        }, $thisBd);
        if ($oddStreak >= 3) {
            $errors[] = 'Назначены смены от 0 до 5 час. подряд ('.$date->format('d.m.Y').')';
        }

        return $errors;
    }

    /**
     * @param  Collection<string, NaryadAssignment>  $byDate
     * @param  callable(?object): bool  $match
     */
    private function streakMatching(CarbonInterface $date, string $routeKey, Collection $byDate, callable $match, mixed $thisBd): int
    {
        if (! $match($thisBd)) {
            return 0;
        }
        $streak = 1;
        for ($i = 1; $i < 10; $i++) {
            $prev = $byDate->get($date->copy()->subDays($i)->toDateString());
            if (! $prev) {
                break;
            }
            $bd = $this->shiftHours->findForRouteOnDate($prev->plan_date, (string) $prev->route_number);
            if (! $match($bd)) {
                break;
            }
            $streak++;
        }

        return $streak;
    }

    /**
     * @return list<string>
     */
    private function checkPersonnelWindows(int $userId, CarbonInterface $date, string $routeKey, bool $isFirst, string $shiftCode): array
    {
        $person = ArmPersonnel::query()->where('user_id', $userId)->first();
        $bd = $this->shiftHours->findForRouteOnDate($date, $routeKey);
        if (! $person || ! $bd) {
            return [];
        }
        $errors = [];
        if (! $isFirst) {
            foreach ($person->early_windows ?? [] as $window) {
                $limit = $window['time'] ?? null;
                if (! $limit) {
                    continue;
                }
                if (FoxTime::toMinutes($bd->start_hours) < FoxTime::toMinutes($limit)) {
                    $errors[] = 'Начало смены раньше раннего выхода ('.$limit.') на '.$date->format('d.m.Y');
                    break;
                }
            }
        }
        if (! in_array($shiftCode, ['3+', '4+'], true)) {
            foreach ($person->late_windows ?? [] as $window) {
                $limit = $window['time'] ?? null;
                if (! $limit) {
                    continue;
                }
                $end = FoxTime::endAt($date, $bd->start_hours, $bd->end_hours);
                $limitAt = FoxTime::at($date, $limit);
                if (FoxTime::toMinutes($limit) < FoxTime::toMinutes(3)) {
                    $limitAt->addDay();
                }
                if ($end->gt($limitAt)) {
                    $errors[] = 'Окончание смены позже позднего окончания ('.$limit.') на '.$date->format('d.m.Y');
                    break;
                }
            }
        }

        return $errors;
    }

    /**
     * @return Collection<string, NaryadAssignment>
     */
    private function assignmentsAround(int $userId, CarbonInterface $date): Collection
    {
        return NaryadAssignment::query()
            ->where('user_id', $userId)
            ->whereBetween('plan_date', [
                $date->copy()->startOfMonth()->subDays(5)->toDateString(),
                $date->copy()->addDay()->toDateString(),
            ])
            ->get()
            ->keyBy(fn (NaryadAssignment $a) => $a->plan_date->toDateString());
    }
}
