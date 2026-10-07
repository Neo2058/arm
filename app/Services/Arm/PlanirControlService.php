<?php

namespace App\Services\Arm;

use App\Enums\UserRole;
use App\Models\ArmPersonnel;
use App\Models\ArmShiftBreakdown;
use App\Models\NaryadAssignment;
use App\Models\NaryadNorm;
use App\Models\NaryadQuota;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class PlanirControlService
{
    public function __construct(
        private PlanirRulesService $planir,
        private ShiftHoursService $shiftHours,
    ) {}

    /**
     * Отчёт полноты сетки (F6 / Shift+F5): не блок клетки.
     *
     * @return array{
     *     month: string,
     *     line_mode: int,
     *     days: list<array<string, mixed>>
     * }
     */
    public function report(string $yearMonth): array
    {
        $start = Carbon::createFromFormat('Y-m', $yearMonth)->startOfMonth();
        $end = $start->copy()->endOfMonth();
        $norm = NaryadNorm::query()->first();
        $planir = $this->planir->settings($norm);
        $shopCombined = trim((string) $planir['shop_combined']);
        $lineMode = (int) $planir['line_mode'];

        $users = User::with('profile')
            ->whereHas('profile')
            ->where('role', UserRole::DRIVER)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
        $personnel = ArmPersonnel::query()
            ->whereIn('user_id', $users->pluck('id'))
            ->get()
            ->keyBy('user_id');

        $assignments = NaryadAssignment::query()
            ->whereBetween('plan_date', [$start->toDateString(), $end->toDateString()])
            ->get()
            ->groupBy(fn (NaryadAssignment $a) => $a->plan_date->toDateString());

        $quotas = NaryadQuota::with('scheduleType')
            ->whereBetween('plan_date', [$start->toDateString(), $end->toDateString()])
            ->get()
            ->keyBy(fn (NaryadQuota $q) => $q->plan_date->toDateString());

        $breakdowns = ArmShiftBreakdown::query()->orderBy('sequence')->get()->groupBy('graph_code');

        $days = [];
        $cursor = $start->copy();
        while ($cursor->lte($end)) {
            $days[] = $this->dayReport(
                $cursor,
                $users,
                $personnel,
                $assignments->get($cursor->toDateString(), collect()),
                $quotas->get($cursor->toDateString()),
                $breakdowns,
                $shopCombined,
                $lineMode
            );
            $cursor->addDay();
        }

        return [
            'month' => $yearMonth,
            'line_mode' => $lineMode,
            'days' => $days,
        ];
    }

    /**
     * @param  Collection<int, User>  $users
     * @param  Collection<int, ArmPersonnel>  $personnel
     * @param  Collection<int, NaryadAssignment>  $dayAssignments
     * @param  Collection<string, Collection<int, ArmShiftBreakdown>>  $breakdowns
     * @return array<string, mixed>
     */
    private function dayReport(
        CarbonInterface $date,
        $users,
        $personnel,
        $dayAssignments,
        ?NaryadQuota $quota,
        $breakdowns,
        string $shopCombined,
        int $lineMode
    ): array {
        $byUser = $dayAssignments->keyBy('user_id');
        $missingPeople = [];
        foreach ($users as $user) {
            $person = $personnel->get($user->id);
            if ($person && $person->fired_on && $person->fired_on->lt($date->copy()->startOfDay())) {
                continue;
            }
            if ($person && $shopCombined !== '' && trim((string) $person->shop_code) === $shopCombined) {
                continue;
            }
            $row = $byUser->get($user->id);
            if (! $row || trim((string) $row->route_number) === '') {
                $position = trim((string) ($person?->position_code ?? ''));
                $missingPeople[] = [
                    'name' => $person?->full_name ?: $user->name,
                    'tab' => $person?->tab_number,
                    'position' => $position,
                ];
            }
        }

        $assignedKeys = [];
        $crewCounts = [];
        foreach ($dayAssignments as $row) {
            $key = trim((string) $row->route_number);
            if ($key === '') {
                continue;
            }
            [$route, $shift] = $this->shiftHours->parseRouteKey($key);
            if ($route === '') {
                continue;
            }
            $slot = $route.'|'.$shift;
            $crewCounts[$slot] = ($crewCounts[$slot] ?? 0) + 1;
            $assignedKeys[$route.'|'.$shift] = true;
            if ($shift !== '') {
                $assignedKeys[$route.'|'] = true;
            }
        }

        $missingShifts = [];
        $shortCrew = [];
        $graphCode = $quota?->scheduleType?->foxpro_code;
        $dayBreakdowns = $graphCode ? $breakdowns->get($graphCode, collect()) : collect();
        $seenSlots = [];
        foreach ($dayBreakdowns as $bd) {
            $position = mb_strtolower(trim((string) $bd->position_code));
            if (in_array($position, ['п', 'p'], true)) {
                continue;
            }
            $slot = $bd->route_code.'|'.$bd->shift_code;
            if (isset($seenSlots[$slot])) {
                continue;
            }
            $seenSlots[$slot] = true;
            $label = $bd->route_code.($bd->shift_code !== '' ? '.'.$bd->shift_code : '');
            $covered = isset($assignedKeys[$slot]) || isset($assignedKeys[$bd->route_code.'|']);
            if (! $covered) {
                $missingShifts[] = $label;
            } elseif ($lineMode === 2 && ($crewCounts[$slot] ?? 0) < 2) {
                $shortCrew[] = $label;
            }
        }

        $duplicates = [];
        foreach ($crewCounts as $slot => $count) {
            $allowed = $lineMode === 2 ? 2 : 1;
            if ($count > $allowed) {
                [$route, $shift] = explode('|', $slot, 2);
                $duplicates[] = $route.($shift !== '' ? '.'.$shift : '').' ('.$count.')';
            }
        }

        return [
            'date' => $date->toDateString(),
            'label' => $date->format('d.m.Y'),
            'graph' => $graphCode,
            'missing_people' => $missingPeople,
            'missing_shifts' => $missingShifts,
            'short_crew' => $shortCrew,
            'duplicates' => $duplicates,
            'has_issues' => $missingPeople !== [] || $missingShifts !== [] || $shortCrew !== [] || $duplicates !== [],
        ];
    }
}
