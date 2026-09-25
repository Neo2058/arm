<?php

namespace App\Services\Arm;

use App\Models\ArmAbsence;
use App\Models\ArmDayAdjustment;
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

        $errors = array_merge($errors, $this->checkHours($userId, $date, $routeKey, $norm, $deviationNames, $isDeviation || $isRest));
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

        if ($planir['enforce_after_night'] && $prevNight && ! $isFirst && ! $isRest && ! $isDeviation) {
            $errors[] = 'После ночной смены должна быть 1-я ('.$date->format('d.m.Y').')';
        }
        if ($planir['enforce_after_first'] && $prevFirst && ! $isRest && ! $isDeviation) {
            $errors[] = 'После 1-й смены должен быть выходной ('.$date->format('d.m.Y').')';
        }

        $errors = array_merge($errors, $this->checkInterval($date, $routeKey, $prev, $byDate, $planir, $deviationNames, $isRest || $isDeviation));
        $errors = array_merge($errors, $this->checkRestSpacing($date, $isRest, $byDate, $planir, $deviationNames));
        $errors = array_merge($errors, $this->checkDaysWithoutRest($date, $isRest || $isDeviation, $byDate, $planir, $deviationNames));
        $errors = array_merge($errors, $this->checkConsecutivePatterns($date, $routeKey, $isRest || $isDeviation, $byDate, $planir));
        $errors = array_merge($errors, $this->checkPersonnelWindows($userId, $date, $routeKey, $isFirst, $shiftCode));

        return array_values(array_filter($errors));
    }

    /**
     * @return array<string, mixed>
     */
    public function settings(?NaryadNorm $norm): array
    {
        $defaults = [
            'interval_hours' => 12,
            'rest_day_hours' => 42,
            'two_rest_days_hours' => 66,
            'min_days_between_rests' => 3,
            'max_days_without_rest' => 12,
            'early_from' => 5.0,
            'early_to' => 8.0,
            'night_shift_codes' => ['3+', '4+', '5+'],
            'rest_codes' => ['В', 'Е', 'ВЫХ'],
            'enforce_after_first' => true,
            'enforce_after_night' => true,
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
     * @param  list<string>  $deviationNames
     * @return list<string>
     */
    private function checkHours(int $userId, CarbonInterface $date, string $routeKey, NaryadNorm $norm, array $deviationNames, bool $skipWorkHours): array
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

        return $errors;
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
    private function checkInterval(CarbonInterface $date, string $routeKey, ?NaryadAssignment $prev, Collection $byDate, array $planir, array $deviationNames, bool $isRest): array
    {
        if ($isRest) {
            return [];
        }
        $thisBd = $this->shiftHours->findForRouteOnDate($date, $routeKey);
        if (! $thisBd || (float) $thisBd->start_hours === 0.0 && (float) $thisBd->end_hours === 0.0) {
            return [];
        }
        $workPrev = $prev;
        $restStreak = 0;
        $cursor = $date->copy()->subDay();
        while ($workPrev && $this->isRestKey((string) $workPrev->route_number, $deviationNames, $planir['rest_codes'])) {
            $restStreak++;
            $cursor->subDay();
            $workPrev = $byDate->get($cursor->toDateString());
        }
        if (! $workPrev) {
            return [];
        }
        $prevBd = $this->shiftHours->findForRouteOnDate($workPrev->plan_date, (string) $workPrev->route_number);
        if (! $prevBd) {
            return [];
        }
        $prevEnd = FoxTime::endAt($workPrev->plan_date, $prevBd->start_hours, $prevBd->end_hours);
        $thisStart = FoxTime::at($date, $thisBd->start_hours);
        $delta = FoxTime::hoursBetween($prevEnd, $thisStart);
        $need = (float) $planir['interval_hours'];
        if ($restStreak >= 1) {
            $need = (float) $planir['rest_day_hours'];
        }
        if ($restStreak >= 2) {
            $need = (float) $planir['two_rest_days_hours'];
        }
        if ($delta < $need) {
            $label = $need >= 60 ? 'двух выходных' : ($need >= 40 ? 'выходного' : 'смен');

            return ['Интервал '.$label.' '.$date->format('d.m.Y').': '.$delta.' ч < '.$need];
        }

        return [];
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
                $date->copy()->subDays(20)->toDateString(),
                $date->copy()->addDay()->toDateString(),
            ])
            ->get()
            ->keyBy(fn (NaryadAssignment $a) => $a->plan_date->toDateString());
    }
}
