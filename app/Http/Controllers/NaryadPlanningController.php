<?php

namespace App\Http\Controllers;

use App\Models\Crew;
use App\Models\DeviationsCatalog;
use App\Models\NaryadAssignment;
use App\Models\NaryadExtraCondition;
use App\Models\NaryadNorm;
use App\Models\NaryadQuota;
use App\Models\NaryadPodstroikaLimit;
use App\Models\Podstroika;
use App\Models\RouteVariant;
use App\Models\ScheduleType;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\ClickHouseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

/**
 * Контроллер уникального инструмента Нарядчика.
 * AJAX переходы между справочниками + сборка сетки наряда.
 * Данные о маршрутах/временах берём из WorkShift + RoutesCatalog (в следующих фазах).
 *
 * Только для ролей naryadchik / dispatcher.
 */
class NaryadPlanningController extends Controller
{
    /**
     * Нормализация роли (поддержка 'dispatcher' и legacy 'naryadchik').
     */
    private function getRoleValue(): string
    {
        $user = Auth::user();
        $roleObj = $user?->role;
        return is_object($roleObj) && property_exists($roleObj, 'value')
            ? strtolower($roleObj->value)
            : strtolower((string) $roleObj);
    }

    private function isNaryadchik(): bool
    {
        return in_array($this->getRoleValue(), ['naryadchik', 'dispatcher'], true);
    }

    private function abortIfNotNaryadchik()
    {
        if (!$this->isNaryadchik()) {
            abort(403, 'Доступ только для нарядчика.');
        }
    }

    /**
     * Главная страница — оболочка с уникальным сайдбаром.
     * Начальный контент — "Сетка".
     */
    public function index()
    {
        $this->abortIfNotNaryadchik();

        $currentMonth = Carbon::now()->startOfMonth();
        $month = request('month', $currentMonth->format('Y-m'));

        return view('naryad.index', [
            'currentMonth' => $month,
            'user' => Auth::user(),
        ]);
    }

    // ===================== AJAX PARTIALS =====================

    public function partialSetka()
    {
        $this->abortIfNotNaryadchik();

        $month = request('month', Carbon::now()->format('Y-m'));
        $start = Carbon::createFromFormat('Y-m', $month)->startOfMonth();
        $daysInMonth = $start->daysInMonth;

        // Только водители (driver) с профилями + планировочные пометки
        $users = User::with('profile')
            ->whereHas('profile')
            ->where('role', 'driver')
            ->orderBy('name')
            ->get();

        // Подстройки для этого месяца (для отображения рядом с сеткой)
        // Группируем по user_id, чтобы отрисовывать напротив фамилии (поддержка нескольких)
        $podstroikas = Podstroika::with('user.profile')
            ->whereIn('user_id', $users->pluck('id')->toArray())
            ->where('for_month', $start)
            ->get()
            ->groupBy('user_id');

        // Лимиты на согласование подстроек на месяц (задаёт нарядчик)
        $podstroikaLimits = NaryadPodstroikaLimit::whereIn('user_id', $users->pluck('id')->toArray())
            ->where('for_month', $start)
            ->get()
            ->keyBy('user_id');

        // Реальные назначения из БД (для отображения уже проставленных маршрутов)
        // Структура: [user_id => ['2026-06-05' => '25', ... ]]
        $assignmentsRaw = NaryadAssignment::whereBetween('plan_date', [$start, $start->copy()->endOfMonth()])
            ->get()
            ->groupBy('user_id')
            ->map(function ($items) {
                return $items->keyBy(fn ($item) => $item->plan_date->format('Y-m-d'))
                             ->map(fn ($item) => $item->route_number);
            })
            ->toArray();

        // Загружаем квоты для подсчёта "назначено vs требуется" и для фильтра по типу графика
        $quotas = NaryadQuota::with('scheduleType')->whereBetween('plan_date', [$start, $start->copy()->endOfMonth()])
            ->get()
            ->keyBy(fn ($q) => $q->plan_date->format('Y-m-d'));

        // Подсчёт назначенных на каждый день
        $dailyAssigned = [];
        foreach ($assignmentsRaw as $userAss) {
            foreach ($userAss as $d => $r) {
                $dailyAssigned[$d] = ($dailyAssigned[$d] ?? 0) + 1;
            }
        }

        // Для модалки: какие полные обозначения маршрутов уже назначены на каждый день (чтобы скрывать использованные)
        $usedRoutesByDate = [];
        foreach ($assignmentsRaw as $userAss) {
            foreach ($userAss as $d => $r) {
                if (!isset($usedRoutesByDate[$d])) {
                    $usedRoutesByDate[$d] = [];
                }
                $usedRoutesByDate[$d][$r] = true;
            }
        }

        $routesCatalog = \App\Models\RoutesCatalog::with('scheduleType')->orderBy('route_number')->get();

        // Variants for route selection in grid (effective routes for different contexts)
        $variants = RouteVariant::with('catalogRoute', 'scheduleType')->where('is_active', true)->get();

        $deviations = DeviationsCatalog::orderBy('name')->get();

        // Для фильтрации вариантов в сетке по типу графика дня
        $dailyGraphs = [];
        foreach ($quotas as $date => $q) {
            $dailyGraphs[$date] = $q->scheduleType ? $q->scheduleType->name : null;
        }

        // Чётность ночи для каждого дня (для фильтрации ночных смен по чёт/нечёт)
        $dailyNightParities = [];
        $days = [];
        $current = $start->copy();
        for ($i = 0; $i < $daysInMonth; $i++) {
            $dstr = $current->format('Y-m-d');
            $dayNum = $current->day;
            $parity = ($dayNum % 2 === 1) ? 'even' : 'odd';  // сегодня 5 (нечёт) → чётная ночь по примеру
            $dailyNightParities[$dstr] = $parity;
            $current->addDay();
        }

        $norm = NaryadNorm::first();

        // Rich details for grid display and modal (start time, loc, end, duration)
        $routeDetails = [];
        $routeHours = []; // numeric hours keyed by same label as assigned value, for exact match in cumulatives
        foreach ($routesCatalog as $r) {
            $val = $r->route_number;
            $lbl = $r->route_number;
            if (!empty($r->shift_type)) {
                $map = [
                    '1' => '1-с ночи',
                    '2' => '2-ранняя',
                    '3' => '3-вечёрка',
                    '3+' => '3+-ранняя ночь',
                    '4+' => '4+-ночь',
                    '5+' => '5+-поздняя ночь',
                ];
                $stype = $map[$r->shift_type] ?? $r->shift_type;
                $lbl .= ' (' . $stype . ')';
                $val = $lbl;
            }
            $st = $r->default_start_time ? \Carbon\Carbon::parse($r->default_start_time)->format('H:i') : '';
            $et = $r->default_end_time ? \Carbon\Carbon::parse($r->default_end_time)->format('H:i') : '';
            $dur = '8ч';
            $h = 8.0;
            if ($r->default_start_time && $r->default_end_time) {
                $s = \Carbon\Carbon::parse($r->default_start_time);
                $e = \Carbon\Carbon::parse($r->default_end_time);
                $mins = $s->diffInMinutes($e);
                if ($mins < 0) $mins += 24 * 60;  // overnight correction for night shifts
                $h = $mins / 60;
                // duration shown in grid cell / title is the full shift span (gross); net after break is handled elsewhere if needed
                $dur = round($h, 1) . 'ч';
            }
            $routeDetails[$val] = [
                'start_time' => $st,
                'start_loc' => $r->start_location,
                'end_time' => $et,
                'end_loc' => $r->end_location,
                'duration' => $dur,
            ];
            $routeHours[$val] = max(0, round($h, 1));

            // Also key by plain route_number so that "from night" continuations like "МЗ-1" resolve to full details in grid cells
            $plain = $r->route_number;
            if ($plain && !isset($routeDetails[$plain])) {
                $routeDetails[$plain] = $routeDetails[$val];
            }
            if ($plain && !isset($routeHours[$plain])) {
                $routeHours[$plain] = $routeHours[$val];
            }
        }
        foreach ($variants as $v) {
            $cat = $v->catalogRoute;
            $lbl = $v->effective_route . " (" . ($v->context === "night" ? "ночь" : ($v->context === "morning" ? "утро" : "любой")) . ($v->catalogRoute ? " из " . $v->catalogRoute->route_number : "") . ")";
            if (!empty($v->shift_type)) {
                $map = [
                    '1' => '1-с ночи',
                    '2' => '2-ранняя',
                    '3' => '3-вечёрка',
                    '3+' => '3+-ранняя ночь',
                    '4+' => '4+-ночь',
                    '5+' => '5+-поздняя ночь',
                ];
                $stype = $map[$v->shift_type] ?? $v->shift_type;
                $lbl .= ' [' . $stype . ']';
            }
            $val = $lbl;
            $st = $v->start_time ? \Carbon\Carbon::parse($v->start_time)->format('H:i') : ($cat && $cat->default_start_time ? \Carbon\Carbon::parse($cat->default_start_time)->format('H:i') : '');
            $et = $v->end_time ? \Carbon\Carbon::parse($v->end_time)->format('H:i') : ($cat && $cat->default_end_time ? \Carbon\Carbon::parse($cat->default_end_time)->format('H:i') : '');
            $dur = '8ч';
            $sTime = $v->start_time ?: ($cat ? $cat->default_start_time : null);
            $eTime = $v->end_time ?: ($cat ? $cat->default_end_time : null);
            $h = 8.0;
            if ($sTime && $eTime) {
                $s = \Carbon\Carbon::parse($sTime);
                $e = \Carbon\Carbon::parse($eTime);
                $mins = $s->diffInMinutes($e);
                if ($mins < 0) $mins += 24 * 60;  // overnight correction for night shifts
                $h = $mins / 60;
                // gross span for display
                $dur = round($h, 1) . 'ч';
            }
            $routeDetails[$val] = [
                'start_time' => $st,
                'start_loc' => $v->start_location ?: ($cat ? $cat->start_location : ''),
                'end_time' => $et,
                'end_loc' => $v->end_location ?: ($cat ? $cat->end_location : ''),
                'duration' => $dur,
            ];
            $routeHours[$val] = max(0, round($h, 1));

            // Also key by plain effective_route so continuations resolve to full details
            $eff = $v->effective_route;
            if ($eff && !isset($routeDetails[$eff])) {
                $routeDetails[$eff] = $routeDetails[$val];
            }
            if ($eff && !isset($routeHours[$eff])) {
                $routeHours[$eff] = $routeHours[$val];
            }
        }

        // === Расчёт накопительных часов для пользователей (месяц/квартал/год + недели) ===
        $monthEnd = $start->copy()->endOfMonth();
        $yearStart = $start->copy()->startOfYear();
        $quarter = $start->quarter;
        $quarterStart = $start->copy()->startOfYear()->addMonths(($quarter - 1) * 3);

        $deviationNames = DeviationsCatalog::pluck('name')->toArray();

        $broaderAssignments = NaryadAssignment::whereIn('user_id', $users->pluck('id')->toArray())
            ->whereBetween('plan_date', [$yearStart, $monthEnd])
            ->get()
            ->groupBy('user_id');

        $monthHours = [];
        $quarterHours = [];
        $yearHours = [];
        $userWeekHours = [];

        foreach ($users as $u) {
            $uAss = $broaderAssignments->get($u->id, collect());

            $monthHours[$u->id] = $this->sumHoursForAssignments($uAss, $deviationNames, $start, $monthEnd, $routeHours, $routeDetails);
            $quarterHours[$u->id] = $this->sumHoursForAssignments($uAss, $deviationNames, $quarterStart, $monthEnd, $routeHours, $routeDetails);
            $yearHours[$u->id] = $this->sumHoursForAssignments($uAss, $deviationNames, $yearStart, $monthEnd, $routeHours, $routeDetails);

            // Карта по неделям (понедельник) для показа остатка при назначении
            $wmap = [];
            foreach ($uAss as $a) {
                if (empty($a) || empty($a->plan_date ?? null)) continue;
                $wkey = $a->plan_date->copy()->startOfWeek(\Carbon\Carbon::MONDAY)->format('Y-m-d');
                if (!isset($wmap[$wkey])) {
                    $wmap[$wkey] = 0;
                }
                $wmap[$wkey] += $this->getHoursFromRouteKey($a->route_number ?? '', $deviationNames, $routeHours, $routeDetails);
            }
            $userWeekHours[$u->id] = [];
            foreach ($wmap as $k => $v) {
                $userWeekHours[$u->id][$k] = round($v, 1);
            }
        }

        // Статистика по отвлечениям (Б, В и любые с short_code) + часы последней смены
        $deviationsForStats = DeviationsCatalog::all();
        $devShortCodeMap = [];
        foreach ($deviationsForStats as $d) {
            $sc = $d->getAttribute('short_code');
            if (!empty($sc)) {
                $devShortCodeMap[$d->name] = $sc;
            }
        }

        $userDevCounts = [];
        $userLastShiftHours = [];

        foreach ($users as $u) {
            $userMonthAss = $assignmentsRaw[$u->id] ?? [];
            $devCounts = [];
            $latestWorkDate = null;
            $latestRoute = null;

            foreach ($userMonthAss as $dateStr => $route) {
                if (isset($devShortCodeMap[$route])) {
                    $code = $devShortCodeMap[$route];
                    $devCounts[$code] = ($devCounts[$code] ?? 0) + 1;
                } else {
                    if ($latestWorkDate === null || $dateStr > $latestWorkDate) {
                        $latestWorkDate = $dateStr;
                        $latestRoute = $route;
                    }
                }
            }

            $lastHours = 0;
            if ($latestRoute) {
                $lastHours = $this->getHoursFromRouteKey($latestRoute, $deviationNames, $routeHours, $routeDetails);
            }

            $userDevCounts[$u->id] = $devCounts;
            $userLastShiftHours[$u->id] = round($lastHours, 1);
        }

        $userHoursJson = json_encode([
            'month'   => $monthHours,
            'quarter' => $quarterHours,
            'year'    => $yearHours,
            'week'    => $userWeekHours,
        ]);
        $weekLimit = $norm ? $norm->week_hours : 40;

        // Precompute JSON for data attributes to avoid Blade parsing issues with complex @json in attributes
        $routesJson = json_encode($routesCatalog->map(function($r) {
            $val = $r->route_number;
            $lbl = $r->route_number;
            if (!empty($r->shift_type)) {
                $map = [
                    '1' => '1-с ночи',
                    '2' => '2-ранняя',
                    '3' => '3-вечёрка',
                    '3+' => '3+-ранняя ночь',
                    '4+' => '4+-ночь',
                    '5+' => '5+-поздняя ночь',
                ];
                $stype = $map[$r->shift_type] ?? $r->shift_type;
                $lbl .= ' (' . $stype . ')';
                $val = $lbl;
            }
            return [
                'value' => $val,
                'label' => $lbl,
                'start_location' => $r->start_location,
                'start_time' => $r->default_start_time,
                'end_location' => $r->end_location,
                'end_time' => $r->default_end_time,
                'break_duration' => $r->default_break_duration ?? 0,
                'schedule_type_name' => $r->scheduleType ? $r->scheduleType->name : null,
                'night_parity' => $r->night_parity,
                'from_night' => $r->from_night,
            ];
        })->toArray());
        $variantsJson = json_encode($variants->map(function($v) {
            $cat = $v->catalogRoute;
            $lbl = $v->effective_route . " (" . ($v->context === "night" ? "ночь" : ($v->context === "morning" ? "утро" : "любой")) . ($v->catalogRoute ? " из " . $v->catalogRoute->route_number : "") . ")";
            if (!empty($v->shift_type)) {
                $map = [
                    '1' => '1-с ночи',
                    '2' => '2-ранняя',
                    '3' => '3-вечёрка',
                    '3+' => '3+-ранняя ночь',
                    '4+' => '4+-ночь',
                    '5+' => '5+-поздняя ночь',
                ];
                $stype = $map[$v->shift_type] ?? $v->shift_type;
                $lbl .= ' [' . $stype . ']';
            }
            $vval = $v->effective_route;
            if (!empty($v->shift_type)) {
                $vval = $lbl;
            }
            return [
                "effective" => $v->effective_route,
                "context" => $v->context,
                "label" => $lbl,
                "value" => $vval,
                "schedule_type_name" => $v->scheduleType ? $v->scheduleType->name : null,
                'start_location' => $v->start_location ?: ($cat ? $cat->start_location : ''),
                'start_time' => $v->start_time ?: ($cat ? $cat->default_start_time : ''),
                'end_location' => $v->end_location ?: ($cat ? $cat->end_location : ''),
                'end_time' => $v->end_time ?: ($cat ? $cat->default_end_time : ''),
                'shift_type' => $v->shift_type,
                'night_parity' => $v->night_parity,
                'from_night' => $v->from_night,
                'break_duration' => ($v->default_break_duration ?? null) ?: ($cat ? $cat->default_break_duration : 0),
            ];
        })->toArray());
        $deviationsJson = json_encode($deviations->map(function($d) {
            return ["value" => $d->name, "label" => "Отвлечение: " . $d->name];
        })->toArray());
        $dailyGraphsJson = json_encode($dailyGraphs);
        $dailyUsedRoutesJson = json_encode($usedRoutesByDate);
        $dailyNightParitiesJson = json_encode($dailyNightParities);

        // Подготовка кэша подстроек для модалки (по id) - поддержка нескольких
        $podstroikasJson = json_encode(
            $podstroikas->flatten()->map(function($p) {
                return [
                    'id' => $p->id,
                    'user_id' => $p->user_id,
                    'for_month' => $p->for_month->format('Y-m'),
                    'details' => $p->details,
                    'status' => $p->status,
                ];
            })->keyBy('id')->toArray()
        );

        return view('naryad.partials.setka', [
            'month' => $month,
            'start' => $start,
            'daysInMonth' => $daysInMonth,
            'users' => $users,
            'assignments' => $assignmentsRaw,
            'quotas' => $quotas,
            'dailyAssigned' => $dailyAssigned,
            'routesCatalog' => $routesCatalog,
            'variants' => $variants,
            'deviations' => $deviations,
            'dailyGraphs' => $dailyGraphs,
            'routesJson' => $routesJson,
            'variantsJson' => $variantsJson,
            'deviationsJson' => $deviationsJson,
            'dailyGraphsJson' => $dailyGraphsJson,
            'dailyUsedRoutesJson' => $dailyUsedRoutesJson,
            'dailyNightParitiesJson' => $dailyNightParitiesJson,
            'norm' => $norm,
            'routeDetails' => $routeDetails,
            'monthHours' => $monthHours,
            'quarterHours' => $quarterHours,
            'yearHours' => $yearHours,
            'userHoursJson' => $userHoursJson,
            'weekLimit' => $weekLimit,
            'userDevCounts' => $userDevCounts,
            'userLastShiftHours' => $userLastShiftHours,
            'podstroikas' => $podstroikas,
            'podstroikasJson' => $podstroikasJson,
            'podstroikaLimits' => $podstroikaLimits,
        ]);
    }

    public function partialCrews()
    {
        $this->abortIfNotNaryadchik();
        $crews = Crew::orderBy('label')->get();
        return view('naryad.partials.crews', [
            'crews' => $crews,
        ]);
    }

    public function partialVariants()
    {
        $this->abortIfNotNaryadchik();
        $variants = RouteVariant::with('catalogRoute', 'scheduleType')->orderBy('effective_route')->get();
        $routesCatalog = \App\Models\RoutesCatalog::with('scheduleType')->orderBy('route_number')->get();
        $scheduleTypes = ScheduleType::orderBy('name')->get();
        return view('naryad.partials.variants', [
            'variants' => $variants,
            'routesCatalog' => $routesCatalog,
            'scheduleTypes' => $scheduleTypes,
        ]);
    }

    public function partialDeviations()
    {
        $this->abortIfNotNaryadchik();
        $deviations = DeviationsCatalog::orderBy('name')->get();
        return view('naryad.partials.deviations', [
            'deviations' => $deviations,
        ]);
    }

    public function partialCalendar()
    {
        $this->abortIfNotNaryadchik();
        $month = request('month', Carbon::now()->format('Y-m'));
        $start = Carbon::createFromFormat('Y-m', $month)->startOfMonth();
        $end = $start->copy()->endOfMonth();

        // Load existing quotas for the month, keyed by Y-m-d for easy lookup in blade
        $quotas = NaryadQuota::whereBetween('plan_date', [$start, $end])
            ->get()
            ->keyBy(fn($q) => $q->plan_date->format('Y-m-d'));

        $scheduleTypes = ScheduleType::orderBy('name')->get();

        return view('naryad.partials.calendar', [
            'month' => $month,
            'quotas' => $quotas,
            'scheduleTypes' => $scheduleTypes,
        ]);
    }

    public function partialTypes()
    {
        $this->abortIfNotNaryadchik();
        $types = ScheduleType::orderBy('name')->get();
        return view('naryad.partials.types', [
            'types' => $types,
        ]);
    }

    public function partialUsers()
    {
        $this->abortIfNotNaryadchik();
        // Расширенный справочник: только водители (driver) с профилями + планировочные пометки
        $users = User::with('profile')
            ->whereHas('profile')
            ->where('role', 'driver')
            ->orderBy('name')
            ->get();

        return view('naryad.partials.users', [
            'users' => $users,
        ]);
    }

    public function partialNorms()
    {
        $this->abortIfNotNaryadchik();
        $norm = NaryadNorm::firstOrCreate([], [
            'year_hours' => 2000,
            'month_hours' => 166,
            'week_hours' => 48, // approx 6/1
            'min_rest_hours' => 8,
        ]);
        $extras = $norm->extraConditions()->orderBy('name')->get();
        return view('naryad.partials.norms', [
            'norm' => $norm,
            'extras' => $extras,
        ]);
    }

    // ===================== ДЕЙСТВИЯ (интерактив сетки и справочников) =====================

    /**
     * Сохранение/обновление назначения маршрута пользователю на дату.
     * Вызывается AJAX из ячеек сетки.
     */
    public function assign(Request $request)
    {
        $this->abortIfNotNaryadchik();

        $data = $request->validate([
            'user_id'      => 'required|exists:users,id',
            'plan_date'    => 'required|date',
            'route_number' => 'required|string|max:50',
            // crew_id, notes — позже
        ]);

        // Overlay checks based on initial conditions (начальные условия)
        $norm = NaryadNorm::first();
        if ($norm) {
            $planDate = \Carbon\Carbon::parse($data['plan_date']);
            $userId = $data['user_id'];

            // Determine if deviation (does not count as work hours)
            $deviationNames = DeviationsCatalog::pluck('name')->toArray();
            $isDeviation = in_array($data['route_number'], $deviationNames);

            // Estimate work hours for this assignment (shift-aware to pick correct duration e.g. 3.8 not 7.2)
            $estimatedHours = $this->getHoursFromRouteKey($data['route_number'], $deviationNames);

            // Week (Mon-Sun)
            $weekStart = $planDate->copy()->startOfWeek(\Carbon\Carbon::MONDAY);
            $weekEnd = $weekStart->copy()->endOfWeek(\Carbon\Carbon::SUNDAY);
            $currentWeek = $this->getUserPlannedWorkHours($userId, $weekStart, $weekEnd, $data['plan_date'], $estimatedHours, $isDeviation);
            $weekLimit = $norm->week_hours;
            if ($currentWeek > $weekLimit) {
                return response()->json([
                    'success' => false,
                    'message' => "Превышен лимит часов в неделю: {$currentWeek} > {$weekLimit}",
                ], 422);
            }

            // Month (use monthly override if set)
            $monthStart = $planDate->copy()->startOfMonth();
            $monthEnd = $planDate->copy()->endOfMonth();
            $monthKey = $planDate->format('Y-m');
            $monthLimit = $norm->monthly_hours[$monthKey] ?? $norm->month_hours;
            $currentMonth = $this->getUserPlannedWorkHours($userId, $monthStart, $monthEnd, $data['plan_date'], $estimatedHours, $isDeviation);
            if ($currentMonth > $monthLimit) {
                return response()->json([
                    'success' => false,
                    'message' => "Превышен лимит часов в месяц ({$monthKey}): {$currentMonth} > {$monthLimit}",
                ], 422);
            }

            // Year
            $yearStart = $planDate->copy()->startOfYear();
            $yearEnd = $planDate->copy()->endOfYear();
            $currentYear = $this->getUserPlannedWorkHours($userId, $yearStart, $yearEnd, $data['plan_date'], $estimatedHours, $isDeviation);
            if ($currentYear > $norm->year_hours) {
                return response()->json([
                    'success' => false,
                    'message' => "Превышен лимит часов в год: {$currentYear} > {$norm->year_hours}",
                ], 422);
            }

            // Basic min rest check (if times provided in future)
            $prev = NaryadAssignment::where('user_id', $userId)
                ->where('plan_date', '<', $data['plan_date'])
                ->orderBy('plan_date', 'desc')
                ->first();
            if ($prev && $prev->end_time && !empty($data['start_time'])) {
                $prevEnd = \Carbon\Carbon::parse($prev->end_time);
                $newStart = \Carbon\Carbon::parse($data['start_time']);
                $restHours = $prevEnd->diffInHours($newStart);
                if ($restHours < $norm->min_rest_hours) {
                    return response()->json([
                        'success' => false,
                        'message' => "Недостаточно отдыха: требуется минимум {$norm->min_rest_hours} ч между сменами.",
                    ], 422);
                }
            }

            // Smarter extra conditions validators
            $extras = $norm->extraConditions()->where('is_active', true)->get();
            foreach ($extras as $extra) {
                $val = (int)$extra->value;
                if ($extra->name == 'max_days_in_row') {
                    $streak = $isDeviation ? 0 : 1;
                    $checkDate = $planDate->copy()->subDay();
                    while (true) {
                        $prevAss = NaryadAssignment::where('user_id', $userId)
                            ->where('plan_date', $checkDate->format('Y-m-d'))
                            ->first();
                        if (!$prevAss) break;
                        $isDev = in_array($prevAss->route_number, $deviationNames);
                        if ($isDev) break;
                        $streak++;
                        if ($streak > $val) {
                            return response()->json([
                                'success' => false,
                                'message' => "Превышен макс. дней подряд: {$streak} > {$val}",
                            ], 422);
                        }
                        $checkDate->subDay();
                    }
                } elseif ($extra->name == 'max_night_shifts') {
                    $periodStart = $planDate->copy()->subDays(30);
                    $nightCount = NaryadAssignment::where('user_id', $userId)
                        ->whereBetween('plan_date', [$periodStart, $planDate])
                        ->get()
                        ->filter(function($a) {
                            return stripos($a->route_number, 'ночь') !== false;
                        })->count();
                    $thisIsNight = stripos($data['route_number'], 'ночь') !== false;
                    if ($thisIsNight) $nightCount++;
                    if ($nightCount > $val) {
                        return response()->json([
                            'success' => false,
                            'message' => "Превышен макс. ночных смен: {$nightCount} > {$val}",
                        ], 422);
                    }
                }
                // add max_consecutive_nights etc as needed
            }
        }

        $assignment = NaryadAssignment::updateOrCreate(
            [
                'user_id'   => $data['user_id'],
                'plan_date' => $data['plan_date'],
            ],
            [
                'route_number' => $data['route_number'],
                'assigned_by'  => Auth::id(),
            ]
        );

        // Логируем действие (как требует AGENTS.md)
        ClickHouseService::log('naryad.assign', $assignment->id, [
            'user_id'     => $data['user_id'],
            'plan_date'   => $data['plan_date'],
            'route_number'=> $data['route_number'],
        ]);

        // Автоподстановка продолжения с ночи на следующий день
        try {
            $assignedKey = $data['route_number'];

            // Парсим базовый номер (для поиска определения from_night)
            $baseRouteNum = $assignedKey;
            if (preg_match('/^([0-9a-zA-Z\-+]+)/', $assignedKey, $m)) {
                $baseRouteNum = $m[1];
            }

            // Ищем from_night в catalog или в variant (поскольку может быть задано на варианте)
            $fromNightNum = null;
            $catRoute = \App\Models\RoutesCatalog::where('route_number', $baseRouteNum)->first();
            if ($catRoute && $catRoute->from_night) {
                $fromNightNum = $catRoute->from_night;
            }
            if (!$fromNightNum) {
                $varRoute = \App\Models\RouteVariant::where('effective_route', $baseRouteNum)->first();
                if ($varRoute && $varRoute->from_night) {
                    $fromNightNum = $varRoute->from_night;
                }
            }

            if ($fromNightNum) {
                // Это ночная смена?
                $isNight = stripos($assignedKey, 'ночь') !== false
                    || ($catRoute && $catRoute->night_parity)
                    || ($varRoute && $varRoute->night_parity);

                if ($isNight) {
                    $nextDate = \Carbon\Carbon::parse($data['plan_date'])->addDay()->format('Y-m-d');

                    // Сохраняем raw from_night как route_number (то, что указано в "с ночи", напр. "МЗ-1").
                    // Полная расшифровка (времена, места) будет взята через plain key в $routeDetails.
                    $contKey = $fromNightNum;

                    NaryadAssignment::updateOrCreate(
                        [
                            'user_id'   => $data['user_id'],
                            'plan_date' => $nextDate,
                        ],
                        [
                            'route_number' => $contKey,
                            'assigned_by'  => Auth::id(),
                        ]
                    );
                }
            }
        } catch (\Exception $e) {
            \Log::warning('Auto night continuation failed: ' . $e->getMessage());
        }

        $response = [
            'success' => true,
            'message' => 'Маршрут назначен',
        ];

        // Подсказка фронту, что мы попытались подставить продолжение (для логов/дебага)
        if (isset($fromNightNum) && $fromNightNum) {
            $response['auto_continuation'] = $fromNightNum;
        }

        return response()->json($response);
    }

    /**
     * Удаление (сброс) назначения смены пользователю на дату.
     * Вызывается AJAX из сетки (кнопка удаления × или выбор пустого значения в селекте).
     */
    public function unassign(Request $request)
    {
        $this->abortIfNotNaryadchik();

        $data = $request->validate([
            'user_id'   => 'required|exists:users,id',
            'plan_date' => 'required|date',
        ]);

        $assignment = NaryadAssignment::where('user_id', $data['user_id'])
            ->where('plan_date', $data['plan_date'])
            ->first();

        if ($assignment) {
            $id = $assignment->id;
            $assignment->delete();

            ClickHouseService::log('naryad.unassign', $id, [
                'user_id'   => $data['user_id'],
                'plan_date' => $data['plan_date'],
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Назначение удалено',
        ]);
    }

    /**
     * Обновление планировочных флагов пользователя (расширенный справочник для нарядчика).
     * AJAX из раздела "Пользователи (планирование)".
     * После сохранения бейджи в Сетке обновятся при следующей загрузке (или принудительной перезагрузке).
     */
    public function updateUserFlags(Request $request, UserProfile $profile)
    {
        $this->abortIfNotNaryadchik();

        $data = $request->validate([
            'is_brigadir'     => 'boolean',
            'can_manage_t6'   => 'boolean',
            'can_maneuvers'   => 'boolean',
            'is_pomoshnik'    => 'boolean',
            'additional_notes'=> 'nullable|string|max:500',
        ]);

        $profile->update([
            'is_brigadir'      => $request->boolean('is_brigadir'),
            'can_manage_t6'    => $request->boolean('can_manage_t6'),
            'can_maneuvers'    => $request->boolean('can_maneuvers'),
            'is_pomoshnik'     => $request->boolean('is_pomoshnik'),
            'additional_notes' => $data['additional_notes'] ?? null,
        ]);

        // Логируем (по AGENTS.md)
        ClickHouseService::log('naryad.user_flags.update', $profile->id, [
            'user_id' => $profile->user_id,
            'flags'   => [
                'is_brigadir'   => $request->boolean('is_brigadir'),
                'can_manage_t6' => $request->boolean('can_manage_t6'),
                'can_maneuvers' => $request->boolean('can_maneuvers'),
                'is_pomoshnik'  => $request->boolean('is_pomoshnik'),
            ],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Флаги планирования обновлены',
        ]);
    }

    /**
     * Создание нового состава (т6 или т5).
     * AJAX из раздела "Составы".
     */
    public function storeCrew(Request $request)
    {
        $this->abortIfNotNaryadchik();

        $data = $request->validate([
            'member1_tab' => 'required|string|max:20',
            'member2_tab' => 'required|string|max:20',
            'type'        => 'required|in:t5,t6',
            'notes'       => 'nullable|string|max:500',
        ]);

        $crew = Crew::create($data); // label генерится автоматически в booted() модели

        ClickHouseService::log('naryad.crew.created', $crew->id, [
            'label' => $crew->label,
            'type'  => $crew->type,
        ]);

        return response()->json(['success' => true]);
    }

    /**
     * Создание нового типа графика.
     */
    public function storeType(Request $request)
    {
        $this->abortIfNotNaryadchik();

        $data = $request->validate([
            'name' => 'required|string|max:100',
            'routes_count' => 'required|integer|min:0',
            'people_per_route' => 'required|integer|min:1',
            'description' => 'nullable|string|max:500',
        ]);

        $type = ScheduleType::create($data);

        ClickHouseService::log('naryad.schedule_type.created', $type->id, [
            'name' => $type->name,
        ]);

        return response()->json(['success' => true]);
    }

    public function updateType(Request $request, ScheduleType $type)
    {
        $this->abortIfNotNaryadchik();

        $data = $request->validate([
            'name' => 'required|string|max:100',
            'routes_count' => 'required|integer|min:0',
            'people_per_route' => 'required|integer|min:1',
            'description' => 'nullable|string|max:500',
        ]);

        $type->update($data);

        ClickHouseService::log('naryad.schedule_type.updated', $type->id, ['name' => $type->name]);

        return response()->json(['success' => true]);
    }

    public function destroyType(ScheduleType $type)
    {
        $this->abortIfNotNaryadchik();

        $id = $type->id;
        $name = $type->name;
        $type->delete();

        ClickHouseService::log('naryad.schedule_type.deleted', $id, ['name' => $name]);

        return response()->json(['success' => true]);
    }

    /**
     * Создание варианта маршрута (для разных контекстов: ночь/утро).
     */
    public function storeVariant(Request $request)
    {
        $this->abortIfNotNaryadchik();

        $data = $request->validate([
            'effective_route' => 'required|string|max:50',
            'schedule_type_id' => 'nullable|exists:schedule_types,id',
            'shift_type' => 'nullable|string|max:10',
            'night_parity' => 'nullable|in:even,odd',
            'from_night' => 'nullable|string|max:50',
            'base_route_number' => 'nullable|string|max:50',
            'route_catalog_id' => 'nullable|exists:routes_catalog,id',
            'start_location' => 'nullable|string|max:255',
            'start_time' => 'nullable',
            'end_location' => 'nullable|string|max:255',
            'end_time' => 'nullable',
            'description' => 'nullable|string|max:500',
            'is_active' => 'boolean',
        ]);

        $variant = RouteVariant::create($data);

        ClickHouseService::log('naryad.route_variant.created', $variant->id, [
            'effective' => $variant->effective_route,
            'context' => $variant->context,
        ]);

        return response()->json(['success' => true]);
    }

    public function updateVariant(Request $request, RouteVariant $variant)
    {
        $this->abortIfNotNaryadchik();

        $data = $request->validate([
            'effective_route' => 'required|string|max:50',
            'schedule_type_id' => 'nullable|exists:schedule_types,id',
            'shift_type' => 'nullable|string|max:10',
            'night_parity' => 'nullable|in:even,odd',
            'from_night' => 'nullable|string|max:50',
            'base_route_number' => 'nullable|string|max:50',
            'route_catalog_id' => 'nullable|exists:routes_catalog,id',
            'start_location' => 'nullable|string|max:255',
            'start_time' => 'nullable',
            'end_location' => 'nullable|string|max:255',
            'end_time' => 'nullable',
            'description' => 'nullable|string|max:500',
            'is_active' => 'boolean',
        ]);

        $variant->update($data);

        ClickHouseService::log('naryad.route_variant.updated', $variant->id, [
            'effective' => $variant->effective_route,
        ]);

        return response()->json(['success' => true]);
    }

    public function destroyVariant(RouteVariant $variant)
    {
        $this->abortIfNotNaryadchik();

        $id = $variant->id;
        $effective = $variant->effective_route;
        $variant->delete();

        ClickHouseService::log('naryad.route_variant.deleted', $id, ['effective' => $effective]);

        return response()->json(['success' => true]);
    }

    /**
     * Создание/редактирование/удаление отвлечения (из DeviationsCatalog).
     * Форма в разделе "Отвлечения".
     */
    public function storeDeviation(Request $request)
    {
        $this->abortIfNotNaryadchik();

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'sys_key' => 'nullable|string|max:50',
            'short_code' => 'nullable|string|max:10',
            'hourly_rate' => 'required|numeric|min:0',
            'default_minutes' => 'required|integer|min:0',
        ]);

        $dev = DeviationsCatalog::create($data);

        ClickHouseService::log('naryad.deviation.created', $dev->id, ['name' => $dev->name]);

        return response()->json(['success' => true]);
    }

    public function updateDeviation(Request $request, DeviationsCatalog $deviation)
    {
        $this->abortIfNotNaryadchik();

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'sys_key' => 'nullable|string|max:50',
            'short_code' => 'nullable|string|max:10',
            'hourly_rate' => 'required|numeric|min:0',
            'default_minutes' => 'required|integer|min:0',
        ]);

        $deviation->update($data);

        ClickHouseService::log('naryad.deviation.updated', $deviation->id, ['name' => $deviation->name]);

        return response()->json(['success' => true]);
    }

    public function destroyDeviation(DeviationsCatalog $deviation)
    {
        $this->abortIfNotNaryadchik();

        $id = $deviation->id;
        $name = $deviation->name;
        $deviation->delete();

        ClickHouseService::log('naryad.deviation.deleted', $id, ['name' => $name]);

        return response()->json(['success' => true]);
    }

    /**
     * Batch сохранение квот календаря за месяц.
     * Принимает массив дней с date, required_crews, schedule_type_id.
     */
    public function saveCalendar(Request $request)
    {
        $this->abortIfNotNaryadchik();

        $validated = $request->validate([
            'month' => 'required|date_format:Y-m',
            'days' => 'required|array',
            'days.*.date' => 'required|date',
            'days.*.required_crews' => 'required|integer|min:0',
            'days.*.schedule_type_id' => 'nullable|exists:schedule_types,id',
        ]);

        foreach ($validated['days'] as $day) {
            NaryadQuota::updateOrCreate(
                ['plan_date' => $day['date']],
                [
                    'required_crews' => $day['required_crews'],
                    'schedule_type_id' => $day['schedule_type_id'] ?? null,
                ]
            );
        }

        ClickHouseService::log('naryad.calendar.saved', 0, [
            'month' => $validated['month'],
            'days_count' => count($validated['days']),
        ]);

        return response()->json(['success' => true]);
    }

    /**
     * Начальные условия для планирования и overlay-проверок.
     */
    public function updateNorm(Request $request)
    {
        $this->abortIfNotNaryadchik();

        $data = $request->validate([
            'year_hours' => 'required|integer|min:0',
            'month_hours' => 'required|integer|min:0',
            'week_hours' => 'required|integer|min:0',
            'min_rest_hours' => 'required|integer|min:0',
            'monthly_hours' => 'nullable|array',
        ]);

        $norm = NaryadNorm::firstOrCreate([]);
        $norm->update($data);

        ClickHouseService::log('naryad.norm.updated', $norm->id, $data);

        return response()->json(['success' => true]);
    }

    public function storeExtraCondition(Request $request)
    {
        $this->abortIfNotNaryadchik();

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'value' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
        ]);

        $norm = NaryadNorm::firstOrCreate([]);
        $extra = $norm->extraConditions()->create([
            'name' => $data['name'],
            'value' => $data['value'],
            'description' => $data['description'] ?? null,
            'is_active' => true,
        ]);

        ClickHouseService::log('naryad.extra_condition.created', $extra->id, $data);

        return response()->json(['success' => true]);
    }

    public function updateExtraCondition(Request $request, NaryadExtraCondition $extra)
    {
        $this->abortIfNotNaryadchik();

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'value' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'is_active' => 'boolean',
        ]);

        $extra->update($data);

        ClickHouseService::log('naryad.extra_condition.updated', $extra->id, $data);

        return response()->json(['success' => true]);
    }

    public function destroyExtraCondition(NaryadExtraCondition $extra)
    {
        $this->abortIfNotNaryadchik();

        $id = $extra->id;
        $name = $extra->name;
        $extra->delete();

        ClickHouseService::log('naryad.extra_condition.deleted', $id, ['name' => $name]);

        return response()->json(['success' => true]);
    }

    public function savePodstroikaLimit(Request $request)
    {
        $this->abortIfNotNaryadchik();

        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'for_month' => 'required|date',
            'max_approved' => 'required|integer|min:0',
        ]);

        NaryadPodstroikaLimit::updateOrCreate(
            [
                'user_id' => $validated['user_id'],
                'for_month' => Carbon::parse($validated['for_month'])->startOfMonth(),
            ],
            ['max_approved' => $validated['max_approved']]
        );

        ClickHouseService::log('naryad.podstroika_limit.saved', $validated['user_id'], [
            'for_month' => $validated['for_month'],
            'max_approved' => $validated['max_approved'],
        ]);

        return response()->json(['success' => true]);
    }

    /**
     * Helper to sum planned work hours for user in period.
     * Skips deviation assignments (they don't consume work hours quota).
     * Adds the current proposed if not deviation.
     */
    private function getUserPlannedWorkHours($userId, $startDate, $endDate, $excludeDate = null, $additionalHours = 0, $isDeviation = false)
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

        if (!$isDeviation) {
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
    private function getHoursFromRouteKey(string $routeKey, array $deviationNames = [], array $precomputedHours = [], array $routeDetails = []): float
    {
        if (empty($routeKey)) {
            return 0;
        }
        if (in_array($routeKey, $deviationNames)) {
            return 0;
        }

        // If this exact key is present in the routeDetails used for grid cells, parse its 'duration' (e.g. "8.3ч").
        // This guarantees the hours added to M/K/G/W are *exactly* the number shown for that assignment in the cell.
        if (!empty($routeDetails) && isset($routeDetails[$routeKey])) {
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
        if (!empty($precomputedHours)) {
            if (isset($precomputedHours[$routeKey])) {
                return $precomputedHours[$routeKey];
            }
            // Tolerant match: find a precomputed entry for same route num + this shift label (handles format/storage variations)
            if ($label) {
                $needle = $routeNum . ' (';
                foreach ($precomputedHours as $k => $hrs) {
                    if (strpos($k, $needle) === 0 && stripos($k, $label) !== false) {
                        return $hrs;
                    }
                }
            }
        }

        $route = \App\Models\RoutesCatalog::where('route_number', $routeNum)
            ->when($shiftCode, fn($q) => $q->where('shift_type', $shiftCode))
            ->first();

        if (!$route) {
            $route = \App\Models\RoutesCatalog::where('route_number', $routeNum)->first();
        }

        if ($route && $route->default_start_time && $route->default_end_time) {
            $s = \Carbon\Carbon::parse($route->default_start_time);
            $e = \Carbon\Carbon::parse($route->default_end_time);
            $minutes = $s->diffInMinutes($e);
            if ($minutes < 0) $minutes += 24 * 60;  // overnight correction for night shifts
            $h = $minutes / 60;
            // Use gross shift duration (start to end) for naryad hours; break handled in other systems if needed
            return max(0, round($h, 1));
        }

        return 8;
    }

    private function getHoursForAssignment($assignment, $deviationNames = [], array $precomputedHours = [], array $routeDetails = [])
    {
        $key = $assignment->route_number ?? null;
        if (empty($key)) {
            return 0;
        }
        $d = is_array($deviationNames) ? $deviationNames : [];
        return $this->getHoursFromRouteKey($key, $d, $precomputedHours, $routeDetails);
    }

    private function sumHoursForAssignments($assignments, $deviationNames, $from = null, $to = null, array $precomputedHours = [], array $routeDetails = [])
    {
        $total = 0;
        foreach ($assignments as $a) {
            if (empty($a) || empty($a->plan_date ?? null)) continue;
            if ($from && $a->plan_date->lt($from)) continue;
            if ($to && $a->plan_date->gt($to)) continue;
            $key = $a->route_number ?? '';
            $total += $this->getHoursFromRouteKey($key, $deviationNames, $precomputedHours, $routeDetails);
        }
        return round($total, 1);
    }
}