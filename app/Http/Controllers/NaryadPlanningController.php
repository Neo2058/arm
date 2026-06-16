<?php

namespace App\Http\Controllers;

use App\Models\Crew;
use App\Models\DeviationsCatalog;
use App\Models\NaryadAssignment;
use App\Models\NaryadExtraCondition;
use App\Models\NaryadNorm;
use App\Models\NaryadQuota;
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

        $routesCatalog = \App\Models\RoutesCatalog::orderBy('route_number')->get();

        // Variants for route selection in grid (effective routes for different contexts)
        $variants = RouteVariant::with('catalogRoute', 'scheduleType')->where('is_active', true)->get();

        $deviations = DeviationsCatalog::orderBy('name')->get();

        // Для фильтрации вариантов в сетке по типу графика дня
        $dailyGraphs = [];
        foreach ($quotas as $date => $q) {
            $dailyGraphs[$date] = $q->scheduleType ? $q->scheduleType->name : null;
        }

        // Precompute JSON for data attributes to avoid Blade parsing issues with complex @json in attributes
        $routesJson = json_encode($routesCatalog->pluck('route_number')->toArray());
        $variantsJson = json_encode($variants->map(function($v) {
            return [
                "effective" => $v->effective_route,
                "context" => $v->context,
                "label" => $v->effective_route . " (" . ($v->context === "night" ? "ночь" : ($v->context === "morning" ? "утро" : "любой")) . ($v->catalogRoute ? " из " . $v->catalogRoute->route_number : "") . ")",
                "schedule_type_name" => $v->scheduleType ? $v->scheduleType->name : null
            ];
        })->toArray());
        $deviationsJson = json_encode($deviations->map(function($d) {
            return ["value" => $d->name, "label" => "Отвлечение: " . $d->name];
        })->toArray());
        $dailyGraphsJson = json_encode($dailyGraphs);

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
        $routesCatalog = \App\Models\RoutesCatalog::orderBy('route_number')->get();
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
            // Basic min rest check (requires previous assignment end_time for accuracy)
            $prev = NaryadAssignment::where('user_id', $data['user_id'])
                ->where('plan_date', '<', $data['plan_date'])
                ->orderBy('plan_date', 'desc')
                ->first();
            if ($prev && $prev->end_time && isset($data['start_time'])) { // if times provided
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
            // TODO: add week/month/year hour caps based on planned assignments + norm values
            // e.g. sum planned hours for user in current week vs $norm->week_hours
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

        return response()->json([
            'success' => true,
            'message' => 'Маршрут назначен',
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
            'context' => 'required|in:morning,night,any',
            'base_route_number' => 'nullable|string|max:50',
            'route_catalog_id' => 'nullable|exists:routes_catalog,id',
            'schedule_type_id' => 'nullable|exists:schedule_types,id',
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
            'context' => 'required|in:morning,night,any',
            'base_route_number' => 'nullable|string|max:50',
            'route_catalog_id' => 'nullable|exists:routes_catalog,id',
            'schedule_type_id' => 'nullable|exists:schedule_types,id',
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
}