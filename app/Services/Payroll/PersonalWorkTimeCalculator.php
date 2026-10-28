<?php

namespace App\Services\Payroll;

use App\Models\WorkShift;
use App\Services\Arm\PlanirRulesService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Personal forecast only; never writes official ARM accounting or planning rows. */
class PersonalWorkTimeCalculator
{
    public function calculate(WorkShift $shift, array $settings): array
    {
        if ($shift->type === 'deviation') {
            return app(PersonalWorkAbsenceCalculator::class)->calculate($shift);
        }
        app(PersonalWorkTimeSettings::class)->validate($settings);
        Validator::make($shift->getAttributes(), [
            'type' => 'required|in:work',
            'shift_date' => 'required|date',
            'started_at' => 'required|date',
            'ended_at' => 'required|date',
            'break_duration' => 'nullable|integer|min:0',
            'shift_code' => 'nullable|string|max:8',
        ])->validate();
        $tz = $settings['timezone'];
        $start = $this->instant($shift->getAttributes()['started_at'], $tz);
        $end = $this->instant($shift->getAttributes()['ended_at'], $tz);
        $duration = $this->minutes($start, $end);
        if ($duration <= 0 || $duration > 7 * 1440) {
            $this->invalid('ended_at', 'Окончание должно быть позже начала; технический предел одной записи — 7 суток.');
        }
        if ($start->toDateString() !== $shift->shift_date->toDateString()) {
            $this->invalid('shift_date', 'Дата смены должна совпадать с датой её начала.');
        }
        if (($settings['month'] ?? $start->startOfMonth()->toDateString()) !== $start->startOfMonth()->toDateString()) {
            $this->invalid('shift_date', 'Для переноса смены в другой месяц требуется новый снимок настроек и новая запись.');
        }
        if ((int) $shift->break_duration > $duration) {
            $this->invalid('break_duration', 'Перерывы не могут быть длиннее смены.');
        }
        $segments = $shift->segments;
        if ($segments === null || $segments === []) {
            $segments = [['kind' => 'line', 'started_at' => $start->toIso8601String(), 'ended_at' => $end->toIso8601String()]];
        }
        Validator::make(['segments' => $segments], [
            'segments' => 'required|array|min:1|max:128',
            'segments.*.kind' => 'required|in:line,reserve,surface',
            'segments.*.started_at' => 'required|date',
            'segments.*.ended_at' => 'required|date',
        ])->validate();
        $totals = [];
        foreach (['line', 'reserve', 'surface'] as $kind) {
            $totals[$kind] = ['paid_minutes' => 0, 'actual_night_minutes' => 0, 'actual_evening_minutes' => 0];
        }
        $cursor = $start;
        foreach ($segments as $segment) {
            $a = $this->instant($segment['started_at'], $tz);
            $b = $this->instant($segment['ended_at'], $tz);
            if (! $a->equalTo($cursor) || $b->lessThanOrEqualTo($a) || $b->greaterThan($end)) {
                $this->invalid('segments', 'Участки должны идти по порядку и полностью покрывать смену без пропусков и пересечений.');
            }
            $kind = $segment['kind'];
            $totals[$kind]['paid_minutes'] += $this->minutes($a, $b);
            $totals[$kind]['actual_evening_minutes'] += $this->overlap($a, $b, $settings['evening_from_minute'], $settings['evening_to_minute']);
            $totals[$kind]['actual_night_minutes'] += $this->overlap($a, $b, $settings['night_from_minute'], $settings['night_to_minute']);
            $cursor = $b;
        }
        if (! $cursor->equalTo($end)) {
            $this->invalid('segments', 'Участки не покрывают окончание смены.');
        }
        $precision = $settings['rounding_decimals'];
        $actualNight = array_sum(array_column($totals, 'actual_night_minutes'));
        $allNight = $settings['night_majority'] && $this->nightMajority([$actualNight], [$duration], $precision);
        $kopecks = 0;
        foreach ($totals as $kind => &$row) {
            $row['night_minutes'] = $allNight ? $row['paid_minutes'] : $row['actual_night_minutes'];
            $row['evening_minutes'] = $allNight ? 0 : $row['actual_evening_minutes'];
            if ($row['paid_minutes'] < $row['night_minutes'] + $row['evening_minutes']) {
                $this->invalid('calculation_snapshot', 'Окна вечера и ночи пересекаются. Проверьте НСИ.');
            }
            $row = array_merge($row, $this->amounts($row, $kind, $settings));
            $kopecks += $row['base_kopecks'] + $row['evening_kopecks'] + $row['night_kopecks'];
        }
        unset($row);

        return [
            'version' => 2, 'paid_minutes' => $duration,
            'paid_break_minutes' => (int) $shift->break_duration,
            'actual_night_minutes' => $actualNight,
            'night_minutes' => array_sum(array_column($totals, 'night_minutes')),
            'evening_minutes' => array_sum(array_column($totals, 'evening_minutes')),
            'all_night' => $allNight, 'by_kind' => $totals,
            'subtotal_kopecks' => $kopecks,
            'calculation_complete' => false,
            'pending' => array_values(array_filter([
                'qualification_seniority_premium_overtime_deductions',
                'rest_chain_and_monthly_rollup',
                in_array($shift->shift_code, ['1', '3+', '4+'], true) ? 'night_continuation_reconciliation' : null,
            ])),
        ];
    }

    /** UCHET rounds each time difference before comparing the sum with half the duty. */
    public function nightMajority(array $nightParts, array $dutyParts, int $precision): bool
    {
        $night = array_sum(array_map(fn ($minutes) => $this->units($minutes, $precision), $nightParts));
        $duty = array_sum(array_map(fn ($minutes) => $this->units($minutes, $precision), $dutyParts));

        return $night > 0 && $duty > 0 && $night * 2 >= $duty;
    }

    /** Derived view only: caller never persists this over the frozen original result. */
    public function creditAllNight(array $result, array $settings): array
    {
        app(PersonalWorkTimeSettings::class)->validate($settings);
        $sum = 0;
        foreach ($result['by_kind'] as $kind => &$row) {
            $row['night_minutes'] = $row['paid_minutes'];
            $row['evening_minutes'] = 0;
            $row = array_merge($row, $this->amounts($row, $kind, $settings));
            $sum += $row['base_kopecks'] + $row['evening_kopecks'] + $row['night_kopecks'];
        }
        unset($row);
        $result['all_night'] = true;
        $result['night_minutes'] = $result['paid_minutes'];
        $result['evening_minutes'] = 0;
        $result['subtotal_kopecks'] = $sum;

        return $result;
    }

    private function amounts(array $row, string $kind, array $settings): array
    {
        $rate = $settings[$kind.'_rate_kopecks'];
        $divisor = 10 ** $settings['rounding_decimals'];

        return [
            'base_kopecks' => $this->divide($rate * $this->units($row['paid_minutes'], $settings['rounding_decimals']), $divisor),
            'evening_kopecks' => $this->divide($rate * $this->units($row['evening_minutes'], $settings['rounding_decimals']) * $settings['evening_percent'], $divisor * 100),
            'night_kopecks' => $this->divide($rate * $this->units($row['night_minutes'], $settings['rounding_decimals']) * $settings['night_percent'], $divisor * 100),
        ];
    }

    /** Reuses PLANIR formula; dutyMinutes includes both parts for a linked night duty. */
    public function rest(CarbonImmutable $previousEnd, CarbonImmutable $nextStart, int $dutyMinutes, int $restDays, array $snapshot): array
    {
        app(PersonalWorkTimeSettings::class)->validate($snapshot);
        if ($dutyMinutes < 0 || $restDays < 0) {
            $this->invalid('rest', 'Продолжительность и число выходных не могут быть отрицательными.');
        }
        $need = app(PlanirRulesService::class)->requiredIntervalHours($dutyMinutes / 60, $restDays, $snapshot['rest_rules']);
        $actual = ($nextStart->getTimestamp() - $previousEnd->getTimestamp()) / 60;

        return ['actual_minutes' => $actual, 'required_minutes' => (int) round($need * 60), 'violation' => $actual < $need * 60];
    }

    private function instant(string $value, string $tz): CarbonImmutable
    {
        $date = CarbonImmutable::parse($value, $tz)->setTimezone($tz);
        if ($date->second !== 0 || $date->micro !== 0) {
            $this->invalid('started_at', 'Укажите время с точностью до минуты.');
        }

        return $date;
    }

    private function overlap(CarbonImmutable $a, CarbonImmutable $b, int $from, int $to): int
    {
        $minutes = 0;
        for ($day = $a->startOfDay()->subDay(); $day->lessThanOrEqualTo($b); $day = $day->addDay()) {
            $left = $day->addMinutes($from);
            $right = ($to < $from ? $day->addDay() : $day)->addMinutes($to);
            $lo = max($a->getTimestamp(), $left->getTimestamp());
            $hi = min($b->getTimestamp(), $right->getTimestamp());
            $minutes += max(0, intdiv($hi - $lo, 60));
        }

        return $minutes;
    }

    private function minutes(CarbonImmutable $a, CarbonImmutable $b): int
    {
        return intdiv($b->getTimestamp() - $a->getTimestamp(), 60);
    }

    private function units(int $minutes, int $precision): int
    {
        return intdiv($minutes * 10 ** $precision + 30, 60);
    }

    private function divide(int $value, int $divisor): int
    {
        return intdiv($value + intdiv($divisor, 2), $divisor);
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
