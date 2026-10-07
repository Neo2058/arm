<?php

namespace App\Services\Arm;

use App\Models\ArmHoliday;
use App\Models\ArmShiftBreakdown;
use App\Models\NaryadAssignment;
use App\Models\NaryadQuota;
use App\Models\RoutesCatalog;
use App\Models\RouteVariant;
use Carbon\Carbon;
use Carbon\CarbonInterface;

class ShiftHoursService
{
    /**
     * Часы смены из разбивки для ключа клетки сетки, либо null если разбивки нет.
     */
    public function hoursForRouteOnDate(CarbonInterface|string $date, string $routeKey): ?float
    {
        $breakdown = $this->findForRouteOnDate($date, $routeKey);

        return $breakdown ? (float) $breakdown->hours_total : null;
    }

    public function findForRouteOnDate(CarbonInterface|string $date, string $routeKey): ?ArmShiftBreakdown
    {
        [$routeCode, $shiftCode] = $this->parseRouteKey($routeKey);
        if ($routeCode === '') {
            return null;
        }

        $planDate = $date instanceof CarbonInterface ? $date->toDateString() : $date;
        $quota = NaryadQuota::with('scheduleType')->whereDate('plan_date', $planDate)->first();
        $graphCode = $quota?->scheduleType?->foxpro_code;

        $query = ArmShiftBreakdown::query()
            ->where('route_code', $routeCode)
            ->orderBy('sequence');

        if ($shiftCode !== '') {
            $query->where(function ($q) use ($shiftCode) {
                $q->where('shift_code', $shiftCode)
                    ->orWhere('shift_code', ltrim($shiftCode, '0'));
            });
        }

        if (filled($graphCode)) {
            $match = (clone $query)->where('graph_code', $graphCode)->first();
            if ($match) {
                return $match;
            }
            if ($quota?->schedule_type_id) {
                $match = (clone $query)->where('schedule_type_id', $quota->schedule_type_id)->first();
                if ($match) {
                    return $match;
                }
            }
        }

        return $query->first();
    }

    public function applyToAssignment(NaryadAssignment $assignment): bool
    {
        $routeKey = (string) $assignment->route_number;
        $breakdown = $this->findForRouteOnDate($assignment->plan_date, $routeKey);
        if (! $breakdown) {
            return false;
        }

        $hours = $breakdown->toAssignmentHours();
        $hours = array_merge($hours, $this->holidayHours($breakdown, $assignment->plan_date));
        if ($breakdown->hasSecondPersonHours()) {
            $hours = array_merge($hours, $breakdown->toSecondPersonHours());
            $hours['two_person'] = true;
        } else {
            unset($hours['hours_holiday_2']);
        }
        $assignment->fill($hours);
        $assignment->save();

        return true;
    }

    /**
     * Праздничные часы текущей клетки (PRAZD1/PRAZDR1 из gath_ch).
     */
    public function holidayHoursForRouteOnDate(CarbonInterface|string $date, string $routeKey): float
    {
        if ($routeKey === '') {
            return 0.0;
        }
        $breakdown = $this->findForRouteOnDate($date, $routeKey);
        if (! $breakdown) {
            return 0.0;
        }
        $planDate = $date instanceof CarbonInterface ? $date : Carbon::parse($date);
        $hours = $this->holidayHours($breakdown, $planDate);

        return (float) $hours['hours_holiday'] + (float) $hours['hours_holiday_reserve'];
    }

    /**
     * @return array{hours_holiday: float, hours_holiday_reserve: float, hours_holiday_2: float}
     */
    public function holidayHours(ArmShiftBreakdown $breakdown, CarbonInterface $date): array
    {
        $line = (float) $breakdown->hours_line;
        $reserve = (float) $breakdown->hours_reserve;
        $line2 = (float) $breakdown->hours_line_2;
        $prazd1 = 0.0;
        $prazdr1 = 0.0;
        $prazd2 = 0.0;
        $end = (float) $breakdown->end_hours;
        $overnightCut = $end > 0 && $end < 4.0;
        $leftover = $overnightCut ? FoxTime::toMinutes($end) / 60 : 0.0;
        $isHoliday = ArmHoliday::query()->whereDate('holiday_date', $date->toDateString())->exists();
        $nextHoliday = ArmHoliday::query()->whereDate('holiday_date', $date->copy()->addDay()->toDateString())->exists();

        if ($isHoliday) {
            if ($overnightCut) {
                $prazdr1 = $reserve - $leftover;
                if ($prazdr1 < 0) {
                    $prazd1 = $line + $prazdr1;
                    $prazdr1 = 0;
                    if ($prazd1 < 0) {
                        $prazd2 = $line2 + $prazd1;
                        $prazd1 = 0;
                    } else {
                        $prazd2 = $line2;
                    }
                } else {
                    $prazd1 = $line;
                    $prazd2 = $line2;
                }
            } else {
                $prazd1 = $line;
                $prazdr1 = $reserve;
                $prazd2 = $line2;
            }
        }

        if ($nextHoliday && $overnightCut) {
            $delta = $leftover;
            if ($line > 0 && $line >= $prazd1) {
                $add = min($delta, $line - $prazd1);
                $prazd1 += $add;
                $delta -= $add;
            }
            if ($delta > 0 && $reserve > 0 && $reserve >= $prazdr1) {
                $add = min($delta, $reserve - $prazdr1);
                $prazdr1 += $add;
                $delta -= $add;
            } elseif ($delta > 0) {
                $prazd2 += $delta;
            }
        }

        return [
            'hours_holiday' => round(max(0, $prazd1), 2),
            'hours_holiday_reserve' => round(max(0, $prazdr1), 2),
            'hours_holiday_2' => round(max(0, $prazd2), 2),
        ];
    }

    public function fromNightOf(string $routeKey): ?string
    {
        [$routeCode] = $this->parseRouteKey($routeKey);
        if ($routeCode === '') {
            return null;
        }
        $from = RoutesCatalog::query()->where('route_number', $routeCode)->value('from_night');
        if (filled($from)) {
            return (string) $from;
        }
        $from = RouteVariant::query()->where('effective_route', $routeCode)->value('from_night');

        return filled($from) ? (string) $from : null;
    }

    /**
     * @return array{0: string, 1: string} [route_code, shift_code]
     */
    public function parseRouteKey(string $routeKey): array
    {
        $routeKey = trim($routeKey);
        if ($routeKey === '') {
            return ['', ''];
        }

        $routeCode = $routeKey;
        $shiftCode = '';

        if (preg_match('/^([0-9A-Za-z\-+]+)/u', $routeKey, $m)) {
            $routeCode = $m[1];
        }

        $labelToCode = [
            '1-с ночи' => '1',
            '2-ранняя' => '2',
            '3-вечёрка' => '3',
            '3-вечерка' => '3',
            '3+-ранняя ночь' => '3+',
            '4+-ночь' => '4+',
            '5+-поздняя ночь' => '5+',
        ];
        if (preg_match('/\((.+)\)\s*$/u', $routeKey, $m)) {
            $label = trim($m[1]);
            $shiftCode = $labelToCode[$label] ?? $label;
        } elseif (preg_match('/\[(.+)\]\s*$/u', $routeKey, $m)) {
            $label = trim($m[1]);
            $shiftCode = $labelToCode[$label] ?? $label;
        }

        return [$routeCode, $shiftCode];
    }
}
