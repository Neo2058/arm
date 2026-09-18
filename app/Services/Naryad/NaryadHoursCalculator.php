<?php

namespace App\Services\Naryad;

use App\Models\DeviationsCatalog;
use App\Models\NaryadAssignment;
use App\Models\RoutesCatalog;
use Carbon\Carbon;

/**
 * Расчёт запланированных часов сетки наряда.
 * Логика перенесена из NaryadPlanningController без изменения формул.
 */
class NaryadHoursCalculator
{
    /**
     * Helper to sum planned work hours for user in period.
     * Skips deviation assignments (they don't consume work hours quota).
     * Adds the current proposed if not deviation.
     */
    public function getUserPlannedWorkHours($userId, $startDate, $endDate, $excludeDate = null, $additionalHours = 0, $isDeviation = false)
    {
        $query = NaryadAssignment::where('user_id', $userId)
            ->whereBetween('plan_date', [$startDate, $endDate]);

        if ($excludeDate) {
            $query->where('plan_date', '!=', $excludeDate);
        }

        $assignments = $query->get();

        $total = 0;
        $deviationNames = DeviationsCatalog::pluck('name')->toArray();

        foreach ($assignments as $a) {
            $h = $this->getHoursFromRouteKey($a->route_number, $deviationNames);
            $total += $h;
        }

        if (! $isDeviation) {
            $total += $additionalHours;
        }

        return round($total, 1);
    }

    /**
     * Вспомогательные методы для подсчёта запланированных часов.
     * Используются для отображения накопительной статистики рядом с ФИО.
     */
    /**
     * Compute hours for a route key string like "1 (1-с ночи)" or plain "25".
     * Prefers the exact duration shown in the grid cell ($routeDetails) so that what user sees as 8.3 is added as 8.3 to M/K/G/W.
     * Falls back to shift-aware catalog lookup.
     */
    public function getHoursFromRouteKey(string $routeKey, array $deviationNames = [], array $precomputedHours = [], array $routeDetails = []): float
    {
        if (empty($routeKey)) {
            return 0;
        }
        if (in_array($routeKey, $deviationNames)) {
            return 0;
        }

        // If this exact key is present in the routeDetails used for grid cells, parse its 'duration' (e.g. "8.3ч").
        // This guarantees the hours added to M/K/G/W are *exactly* the number shown for that assignment in the cell.
        if (! empty($routeDetails) && isset($routeDetails[$routeKey])) {
            $d = $routeDetails[$routeKey]['duration'] ?? '';
            if (preg_match('/([\d.]+)/', $d, $mm)) {
                return (float) $mm[1];
            }
        }

        // Parse num + optional shift label early
        $routeNum = $routeKey;
        $shiftCode = null;
        $label = null;
        if (preg_match('/^([0-9a-zA-Z\-+]+)/', $routeKey, $m)) {
            $routeNum = $m[1];
        }
        if (preg_match('/\((.+)\)$/', $routeKey, $m)) {
            $label = $m[1];
            $labelToCode = array_flip([
                '1' => '1-с ночи',
                '2' => '2-ранняя',
                '3' => '3-вечёрка',
                '3+' => '3+-ранняя ночь',
                '4+' => '4+-ночь',
                '5+' => '5+-поздняя ночь',
            ]);
            $shiftCode = $labelToCode[$label] ?? null;
        }

        // Prefer precomputed hours for the label if present (ensures M/K/G/W exactly match the duration shown in grid cell for this key)
        if (! empty($precomputedHours)) {
            if (isset($precomputedHours[$routeKey])) {
                return $precomputedHours[$routeKey];
            }
            // Tolerant match: find a precomputed entry for same route num + this shift label (handles format/storage variations)
            if ($label) {
                $needle = $routeNum.' (';
                foreach ($precomputedHours as $k => $hrs) {
                    if (strpos($k, $needle) === 0 && stripos($k, $label) !== false) {
                        return $hrs;
                    }
                }
            }
        }

        $route = RoutesCatalog::where('route_number', $routeNum)
            ->when($shiftCode, fn ($q) => $q->where('shift_type', $shiftCode))
            ->first();

        if (! $route) {
            $route = RoutesCatalog::where('route_number', $routeNum)->first();
        }

        if ($route && $route->default_start_time && $route->default_end_time) {
            $s = Carbon::parse($route->default_start_time);
            $e = Carbon::parse($route->default_end_time);
            $minutes = $s->diffInMinutes($e);
            if ($minutes < 0) {
                $minutes += 24 * 60;
            }  // overnight correction for night shifts
            $h = $minutes / 60;

            // Use gross shift duration (start to end) for naryad hours; break handled in other systems if needed
            return max(0, round($h, 1));
        }

        return 8;
    }

    public function getHoursForAssignment($assignment, $deviationNames = [], array $precomputedHours = [], array $routeDetails = [])
    {
        $key = $assignment->route_number ?? null;
        if (empty($key)) {
            return 0;
        }
        $d = is_array($deviationNames) ? $deviationNames : [];

        return $this->getHoursFromRouteKey($key, $d, $precomputedHours, $routeDetails);
    }

    public function sumHoursForAssignments($assignments, $deviationNames, $from = null, $to = null, array $precomputedHours = [], array $routeDetails = [])
    {
        $total = 0;
        foreach ($assignments as $a) {
            if (empty($a) || empty($a->plan_date ?? null)) {
                continue;
            }
            if ($from && $a->plan_date->lt($from)) {
                continue;
            }
            if ($to && $a->plan_date->gt($to)) {
                continue;
            }
            $key = $a->route_number ?? '';
            $total += $this->getHoursFromRouteKey($key, $deviationNames, $precomputedHours, $routeDetails);
        }

        return round($total, 1);
    }
}
