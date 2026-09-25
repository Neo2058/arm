<?php

namespace App\Services\Arm;

use App\Models\ArmShiftBreakdown;
use App\Models\NaryadAssignment;
use App\Models\NaryadQuota;
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
        if ($breakdown->hasSecondPersonHours()) {
            $hours = array_merge($hours, $breakdown->toSecondPersonHours());
            $hours['two_person'] = true;
        }
        $assignment->fill($hours);
        $assignment->save();

        return true;
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
