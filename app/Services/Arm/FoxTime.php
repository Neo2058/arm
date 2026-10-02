<?php

namespace App\Services\Arm;

use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Время АРМ: 5.30 = 05:30, не 5.3 часа.
 */
class FoxTime
{
    public static function toMinutes(float|int|string|null $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }
        $formatted = sprintf('%05.2f', (float) $value);
        $hours = (int) substr($formatted, 0, 2);
        $minutes = (int) substr($formatted, -2);

        return ($hours * 60) + $minutes;
    }

    public static function at(CarbonInterface $date, float|int|string|null $value): Carbon
    {
        return $date->copy()->startOfDay()->addMinutes(self::toMinutes($value));
    }

    public static function endAt(CarbonInterface $date, float|int|string|null $start, float|int|string|null $end): Carbon
    {
        $startAt = self::at($date, $start);
        $endAt = self::at($date, $end);
        if ($endAt->lte($startAt)) {
            $endAt->addDay();
        }

        return $endAt;
    }

    public static function hoursBetween(CarbonInterface $from, CarbonInterface $to): float
    {
        return round(($to->getTimestamp() - $from->getTimestamp()) / 3600, 2);
    }

    /** Как STR(чч.мм, 5, 2) в FoxPro: « 5.30». */
    public static function format(float|int|string|null $value): string
    {
        $minutes = self::toMinutes($value);
        $hours = intdiv($minutes, 60);

        return sprintf('%2d.%02d', $hours, $minutes % 60);
    }
}
