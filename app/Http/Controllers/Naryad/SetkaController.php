<?php

namespace App\Http\Controllers\Naryad;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\DeviationsCatalog;
use App\Models\NaryadAssignment;
use App\Models\NaryadNorm;
use App\Models\NaryadPodstroikaLimit;
use App\Models\NaryadQuota;
use App\Models\Podstroika;
use App\Models\RoutesCatalog;
use App\Models\RouteVariant;
use App\Models\User;
use App\Services\ClickHouseService;
use App\Services\Naryad\NaryadHoursCalculator;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Support\Facades\Auth;

class SetkaController extends Controller implements HasMiddleware
{
    use EnsuresDispatcher;

    public function __construct(private NaryadHoursCalculator $hours) {}

    public function partialSetka()
    {
        $this->abortIfNotDispatcher();

        $month = request('month', Carbon::now()->format('Y-m'));
        $start = Carbon::createFromFormat('Y-m', $month)->startOfMonth();
        $daysInMonth = $start->daysInMonth;

        // Только водители (driver) с профилями + планировочные пометки
        $users = User::with('profile')
            ->whereHas('profile')
            ->where('role', UserRole::DRIVER)
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
                if (! isset($usedRoutesByDate[$d])) {
                    $usedRoutesByDate[$d] = [];
                }
                $usedRoutesByDate[$d][$r] = true;
            }
        }

        $routesCatalog = RoutesCatalog::with('scheduleType')->orderBy('route_number')->get();

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
            if (! empty($r->shift_type)) {
                $map = [
                    '1' => '1-с ночи',
                    '2' => '2-ранняя',
                    '3' => '3-вечёрка',
                    '3+' => '3+-ранняя ночь',
                    '4+' => '4+-ночь',
                    '5+' => '5+-поздняя ночь',
                ];
                $stype = $map[$r->shift_type] ?? $r->shift_type;
                $lbl .= ' ('.$stype.')';
                $val = $lbl;
            }
            $st = $r->default_start_time ? Carbon::parse($r->default_start_time)->format('H:i') : '';
            $et = $r->default_end_time ? Carbon::parse($r->default_end_time)->format('H:i') : '';
            $dur = '8ч';
            $h = 8.0;
            if ($r->default_start_time && $r->default_end_time) {
                $s = Carbon::parse($r->default_start_time);
                $e = Carbon::parse($r->default_end_time);
                $mins = $s->diffInMinutes($e);
                if ($mins < 0) {
                    $mins += 24 * 60;
                }  // overnight correction for night shifts
                $h = $mins / 60;
                // duration shown in grid cell / title is the full shift span (gross); net after break is handled elsewhere if needed
                $dur = round($h, 1).'ч';
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
            if ($plain && ! isset($routeDetails[$plain])) {
                $routeDetails[$plain] = $routeDetails[$val];
            }
            if ($plain && ! isset($routeHours[$plain])) {
                $routeHours[$plain] = $routeHours[$val];
            }
        }
        foreach ($variants as $v) {
            $cat = $v->catalogRoute;
            $lbl = $v->effective_route.' ('.($v->context === 'night' ? 'ночь' : ($v->context === 'morning' ? 'утро' : 'любой')).($v->catalogRoute ? ' из '.$v->catalogRoute->route_number : '').')';
            if (! empty($v->shift_type)) {
                $map = [
                    '1' => '1-с ночи',
                    '2' => '2-ранняя',
                    '3' => '3-вечёрка',
                    '3+' => '3+-ранняя ночь',
                    '4+' => '4+-ночь',
                    '5+' => '5+-поздняя ночь',
                ];
                $stype = $map[$v->shift_type] ?? $v->shift_type;
                $lbl .= ' ['.$stype.']';
            }
            $val = $lbl;
            $st = $v->start_time ? Carbon::parse($v->start_time)->format('H:i') : ($cat && $cat->default_start_time ? Carbon::parse($cat->default_start_time)->format('H:i') : '');
            $et = $v->end_time ? Carbon::parse($v->end_time)->format('H:i') : ($cat && $cat->default_end_time ? Carbon::parse($cat->default_end_time)->format('H:i') : '');
            $dur = '8ч';
            $sTime = $v->start_time ?: ($cat ? $cat->default_start_time : null);
            $eTime = $v->end_time ?: ($cat ? $cat->default_end_time : null);
            $h = 8.0;
            if ($sTime && $eTime) {
                $s = Carbon::parse($sTime);
                $e = Carbon::parse($eTime);
                $mins = $s->diffInMinutes($e);
                if ($mins < 0) {
                    $mins += 24 * 60;
                }  // overnight correction for night shifts
                $h = $mins / 60;
                // gross span for display
                $dur = round($h, 1).'ч';
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
            if ($eff && ! isset($routeDetails[$eff])) {
                $routeDetails[$eff] = $routeDetails[$val];
            }
            if ($eff && ! isset($routeHours[$eff])) {
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

            $monthHours[$u->id] = $this->hours->sumHoursForAssignments($uAss, $deviationNames, $start, $monthEnd, $routeHours, $routeDetails);
            $quarterHours[$u->id] = $this->hours->sumHoursForAssignments($uAss, $deviationNames, $quarterStart, $monthEnd, $routeHours, $routeDetails);
            $yearHours[$u->id] = $this->hours->sumHoursForAssignments($uAss, $deviationNames, $yearStart, $monthEnd, $routeHours, $routeDetails);

            // Карта по неделям (понедельник) для показа остатка при назначении
            $wmap = [];
            foreach ($uAss as $a) {
                if (empty($a) || empty($a->plan_date ?? null)) {
                    continue;
                }
                $wkey = $a->plan_date->copy()->startOfWeek(Carbon::MONDAY)->format('Y-m-d');
                if (! isset($wmap[$wkey])) {
                    $wmap[$wkey] = 0;
                }
                $wmap[$wkey] += $this->hours->getHoursFromRouteKey($a->route_number ?? '', $deviationNames, $routeHours, $routeDetails);
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
            if (! empty($sc)) {
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
                $lastHours = $this->hours->getHoursFromRouteKey($latestRoute, $deviationNames, $routeHours, $routeDetails);
            }

            $userDevCounts[$u->id] = $devCounts;
            $userLastShiftHours[$u->id] = round($lastHours, 1);
        }

        $userHoursJson = json_encode([
            'month' => $monthHours,
            'quarter' => $quarterHours,
            'year' => $yearHours,
            'week' => $userWeekHours,
        ]);
        $weekLimit = $norm ? $norm->week_hours : 40;

        // Precompute JSON for data attributes to avoid Blade parsing issues with complex @json in attributes
        $routesJson = json_encode($routesCatalog->map(function ($r) {
            $val = $r->route_number;
            $lbl = $r->route_number;
            if (! empty($r->shift_type)) {
                $map = [
                    '1' => '1-с ночи',
                    '2' => '2-ранняя',
                    '3' => '3-вечёрка',
                    '3+' => '3+-ранняя ночь',
                    '4+' => '4+-ночь',
                    '5+' => '5+-поздняя ночь',
                ];
                $stype = $map[$r->shift_type] ?? $r->shift_type;
                $lbl .= ' ('.$stype.')';
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
        $variantsJson = json_encode($variants->map(function ($v) {
            $cat = $v->catalogRoute;
            $lbl = $v->effective_route.' ('.($v->context === 'night' ? 'ночь' : ($v->context === 'morning' ? 'утро' : 'любой')).($v->catalogRoute ? ' из '.$v->catalogRoute->route_number : '').')';
            if (! empty($v->shift_type)) {
                $map = [
                    '1' => '1-с ночи',
                    '2' => '2-ранняя',
                    '3' => '3-вечёрка',
                    '3+' => '3+-ранняя ночь',
                    '4+' => '4+-ночь',
                    '5+' => '5+-поздняя ночь',
                ];
                $stype = $map[$v->shift_type] ?? $v->shift_type;
                $lbl .= ' ['.$stype.']';
            }
            $vval = $v->effective_route;
            if (! empty($v->shift_type)) {
                $vval = $lbl;
            }

            return [
                'effective' => $v->effective_route,
                'context' => $v->context,
                'label' => $lbl,
                'value' => $vval,
                'schedule_type_name' => $v->scheduleType ? $v->scheduleType->name : null,
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
        $deviationsJson = json_encode($deviations->map(function ($d) {
            return ['value' => $d->name, 'label' => 'Отвлечение: '.$d->name];
        })->toArray());
        $dailyGraphsJson = json_encode($dailyGraphs);
        $dailyUsedRoutesJson = json_encode($usedRoutesByDate);
        $dailyNightParitiesJson = json_encode($dailyNightParities);

        // Подготовка кэша подстроек для модалки (по id) - поддержка нескольких
        $podstroikasJson = json_encode(
            $podstroikas->flatten()->map(function ($p) {
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

    public function assign(Request $request)
    {
        $this->abortIfNotDispatcher();

        $data = $request->validate([
            'user_id' => 'required|exists:users,id',
            'plan_date' => 'required|date',
            'route_number' => 'required|string|max:50',
            // crew_id, notes — позже
        ]);

        // Overlay checks based on initial conditions (начальные условия)
        $norm = NaryadNorm::first();
        if ($norm) {
            $planDate = Carbon::parse($data['plan_date']);
            $userId = $data['user_id'];

            // Determine if deviation (does not count as work hours)
            $deviationNames = DeviationsCatalog::pluck('name')->toArray();
            $isDeviation = in_array($data['route_number'], $deviationNames);

            // Estimate work hours for this assignment (shift-aware to pick correct duration e.g. 3.8 not 7.2)
            $estimatedHours = $this->hours->getHoursFromRouteKey($data['route_number'], $deviationNames);

            // Week (Mon-Sun)
            $weekStart = $planDate->copy()->startOfWeek(Carbon::MONDAY);
            $weekEnd = $weekStart->copy()->endOfWeek(Carbon::SUNDAY);
            $currentWeek = $this->hours->getUserPlannedWorkHours($userId, $weekStart, $weekEnd, $data['plan_date'], $estimatedHours, $isDeviation);
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
            $currentMonth = $this->hours->getUserPlannedWorkHours($userId, $monthStart, $monthEnd, $data['plan_date'], $estimatedHours, $isDeviation);
            if ($currentMonth > $monthLimit) {
                return response()->json([
                    'success' => false,
                    'message' => "Превышен лимит часов в месяц ({$monthKey}): {$currentMonth} > {$monthLimit}",
                ], 422);
            }

            // Year
            $yearStart = $planDate->copy()->startOfYear();
            $yearEnd = $planDate->copy()->endOfYear();
            $currentYear = $this->hours->getUserPlannedWorkHours($userId, $yearStart, $yearEnd, $data['plan_date'], $estimatedHours, $isDeviation);
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
            if ($prev && $prev->end_time && ! empty($data['start_time'])) {
                $prevEnd = Carbon::parse($prev->end_time);
                $newStart = Carbon::parse($data['start_time']);
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
                $val = (int) $extra->value;
                if ($extra->name == 'max_days_in_row') {
                    $streak = $isDeviation ? 0 : 1;
                    $checkDate = $planDate->copy()->subDay();
                    while (true) {
                        $prevAss = NaryadAssignment::where('user_id', $userId)
                            ->where('plan_date', $checkDate->format('Y-m-d'))
                            ->first();
                        if (! $prevAss) {
                            break;
                        }
                        $isDev = in_array($prevAss->route_number, $deviationNames);
                        if ($isDev) {
                            break;
                        }
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
                        ->filter(function ($a) {
                            return stripos($a->route_number, 'ночь') !== false;
                        })->count();
                    $thisIsNight = stripos($data['route_number'], 'ночь') !== false;
                    if ($thisIsNight) {
                        $nightCount++;
                    }
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
                'user_id' => $data['user_id'],
                'plan_date' => $data['plan_date'],
            ],
            [
                'route_number' => $data['route_number'],
                'assigned_by' => Auth::id(),
            ]
        );

        // Логируем действие (как требует AGENTS.md)
        ClickHouseService::log('naryad.assign', $assignment->id, [
            'user_id' => $data['user_id'],
            'plan_date' => $data['plan_date'],
            'route_number' => $data['route_number'],
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
            $catRoute = RoutesCatalog::where('route_number', $baseRouteNum)->first();
            if ($catRoute && $catRoute->from_night) {
                $fromNightNum = $catRoute->from_night;
            }
            if (! $fromNightNum) {
                $varRoute = RouteVariant::where('effective_route', $baseRouteNum)->first();
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
                    $nextDate = Carbon::parse($data['plan_date'])->addDay()->format('Y-m-d');

                    // Сохраняем raw from_night как route_number (то, что указано в "с ночи", напр. "МЗ-1").
                    // Полная расшифровка (времена, места) будет взята через plain key в $routeDetails.
                    $contKey = $fromNightNum;

                    NaryadAssignment::updateOrCreate(
                        [
                            'user_id' => $data['user_id'],
                            'plan_date' => $nextDate,
                        ],
                        [
                            'route_number' => $contKey,
                            'assigned_by' => Auth::id(),
                        ]
                    );
                }
            }
        } catch (\Exception $e) {
            \Log::warning('Auto night continuation failed: '.$e->getMessage());
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
        $this->abortIfNotDispatcher();

        $data = $request->validate([
            'user_id' => 'required|exists:users,id',
            'plan_date' => 'required|date',
        ]);

        $assignment = NaryadAssignment::where('user_id', $data['user_id'])
            ->whereDate('plan_date', $data['plan_date'])
            ->first();

        if ($assignment) {
            $id = $assignment->id;
            $assignment->delete();

            ClickHouseService::log('naryad.unassign', $id, [
                'user_id' => $data['user_id'],
                'plan_date' => $data['plan_date'],
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Назначение удалено',
        ]);
    }

    public function savePodstroikaLimit(Request $request)
    {
        $this->abortIfNotDispatcher();

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
}
