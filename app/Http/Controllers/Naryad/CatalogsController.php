<?php

namespace App\Http\Controllers\Naryad;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Crew;
use App\Models\DeviationsCatalog;
use App\Models\NaryadExtraCondition;
use App\Models\NaryadNorm;
use App\Models\NaryadQuota;
use App\Models\RoutesCatalog;
use App\Models\RouteVariant;
use App\Models\ScheduleType;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\ClickHouseService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;

class CatalogsController extends Controller implements HasMiddleware
{
    use EnsuresDispatcher;

    public function partialCrews()
    {
        $this->abortIfNotDispatcher();
        $crews = Crew::orderBy('label')->get();

        return view('naryad.partials.crews', [
            'crews' => $crews,
        ]);
    }

    public function partialVariants()
    {
        $this->abortIfNotDispatcher();
        $variants = RouteVariant::with('catalogRoute', 'scheduleType')->orderBy('effective_route')->get();
        $routesCatalog = RoutesCatalog::with('scheduleType')->orderBy('route_number')->get();
        $scheduleTypes = ScheduleType::orderBy('name')->get();

        return view('naryad.partials.variants', [
            'variants' => $variants,
            'routesCatalog' => $routesCatalog,
            'scheduleTypes' => $scheduleTypes,
        ]);
    }

    public function partialDeviations()
    {
        $this->abortIfNotDispatcher();
        $deviations = DeviationsCatalog::orderBy('name')->get();

        return view('naryad.partials.deviations', [
            'deviations' => $deviations,
        ]);
    }

    public function partialCalendar()
    {
        $this->abortIfNotDispatcher();
        $month = request('month', Carbon::now()->format('Y-m'));
        $start = Carbon::createFromFormat('Y-m', $month)->startOfMonth();
        $end = $start->copy()->endOfMonth();

        // Load existing quotas for the month, keyed by Y-m-d for easy lookup in blade
        $quotas = NaryadQuota::whereBetween('plan_date', [$start, $end])
            ->get()
            ->keyBy(fn ($q) => $q->plan_date->format('Y-m-d'));

        $scheduleTypes = ScheduleType::orderBy('name')->get();

        return view('naryad.partials.calendar', [
            'month' => $month,
            'quotas' => $quotas,
            'scheduleTypes' => $scheduleTypes,
        ]);
    }

    public function partialTypes()
    {
        $this->abortIfNotDispatcher();
        $types = ScheduleType::orderBy('name')->get();

        return view('naryad.partials.types', [
            'types' => $types,
        ]);
    }

    public function partialUsers()
    {
        $this->abortIfNotDispatcher();
        // Расширенный справочник: только водители (driver) с профилями + планировочные пометки
        $users = User::with('profile')
            ->whereHas('profile')
            ->where('role', UserRole::DRIVER)
            ->orderBy('name')
            ->get();

        return view('naryad.partials.users', [
            'users' => $users,
        ]);
    }

    public function partialNorms()
    {
        $this->abortIfNotDispatcher();
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

    public function updateUserFlags(Request $request, UserProfile $profile)
    {
        $this->abortIfNotDispatcher();

        $data = $request->validate([
            'is_brigadir' => 'boolean',
            'can_manage_t6' => 'boolean',
            'can_maneuvers' => 'boolean',
            'is_pomoshnik' => 'boolean',
            'additional_notes' => 'nullable|string|max:500',
        ]);

        $profile->update([
            'is_brigadir' => $request->boolean('is_brigadir'),
            'can_manage_t6' => $request->boolean('can_manage_t6'),
            'can_maneuvers' => $request->boolean('can_maneuvers'),
            'is_pomoshnik' => $request->boolean('is_pomoshnik'),
            'additional_notes' => $data['additional_notes'] ?? null,
        ]);

        // Логируем (по AGENTS.md)
        ClickHouseService::log('naryad.user_flags.update', $profile->id, [
            'user_id' => $profile->user_id,
            'flags' => [
                'is_brigadir' => $request->boolean('is_brigadir'),
                'can_manage_t6' => $request->boolean('can_manage_t6'),
                'can_maneuvers' => $request->boolean('can_maneuvers'),
                'is_pomoshnik' => $request->boolean('is_pomoshnik'),
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
        $this->abortIfNotDispatcher();

        $data = $request->validate([
            'member1_tab' => 'required|string|max:20',
            'member2_tab' => 'required|string|max:20',
            'type' => 'required|in:t5,t6',
            'notes' => 'nullable|string|max:500',
        ]);

        $crew = Crew::create($data); // label генерится автоматически в booted() модели

        ClickHouseService::log('naryad.crew.created', $crew->id, [
            'label' => $crew->label,
            'type' => $crew->type,
        ]);

        return response()->json(['success' => true]);
    }

    /**
     * Создание нового типа графика.
     */
    public function storeType(Request $request)
    {
        $this->abortIfNotDispatcher();

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
        $this->abortIfNotDispatcher();

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
        $this->abortIfNotDispatcher();

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
        $this->abortIfNotDispatcher();

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
        $this->abortIfNotDispatcher();

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
        $this->abortIfNotDispatcher();

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
        $this->abortIfNotDispatcher();

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
        $this->abortIfNotDispatcher();

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
        $this->abortIfNotDispatcher();

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
        $this->abortIfNotDispatcher();

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
        $this->abortIfNotDispatcher();

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
        $this->abortIfNotDispatcher();

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
        $this->abortIfNotDispatcher();

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
        $this->abortIfNotDispatcher();

        $id = $extra->id;
        $name = $extra->name;
        $extra->delete();

        ClickHouseService::log('naryad.extra_condition.deleted', $id, ['name' => $name]);

        return response()->json(['success' => true]);
    }
}
