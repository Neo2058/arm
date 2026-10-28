<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeviationsCatalog;
use App\Models\RoutesCatalog;
use App\Models\User;
use App\Models\WorkShift;
use App\Services\ClickHouseService;
use App\Services\Payroll\PayrollService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class WorkShiftController extends Controller
{
    /**
     * Отдает смены текущего пользователя за определенный месяц и весь каталог маршрутов
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        $month = $request->query('month', date('m'));
        $year = $request->query('year', date('Y'));

        $start = Carbon::create($year, $month, 1)->startOfMonth();
        $end = Carbon::create($year, $month, 1)->endOfMonth();

        // Каждая учетка видит СТРОГО свои смены (Высший приоритет конфиденциальности)
        $shifts = WorkShift::where('user_id', $user->id)
            ->where('calculation_version', 1)
            ->whereBetween('shift_date', [$start, $end])
            ->with([
                'route:id,route_number,start_location,end_location,default_start_time,default_end_time,default_break_duration,technological_tasks', 'deviation',
            ])
            ->get();

        // Отдаем полный каталог маршрутов для выпадающего списка
        $catalog = RoutesCatalog::all();
        // ПОДГРУЖАЕМ СПРАВОЧНИК ОТВЛЕЧЕНИЙ ДЛЯ ФРОНТЕНДА
        $deviationsCatalog = DeviationsCatalog::all();

        return response()->json([
            'shifts' => $shifts,
            'catalog' => $catalog,
            'deviations_catalog' => $deviationsCatalog, // <-- Улетело в React
        ]);
    }

    /**
     * Сохранение новой или обновление измененной смены/отвлечения
     */
    public function store(Request $request, PayrollService $payroll)
    {
        $data = $request->validate([
            'shift_date' => 'required|date',
            'type' => 'required|in:work,deviation',

            'deviation_id' => 'nullable|exists:deviations_catalog,id', // Проверка по ID каталога
            'route_id' => 'nullable|exists:routes_catalog,id',

            'started_at' => 'nullable|date',
            'ended_at' => 'nullable|date',

            'start_location' => 'nullable|string',
            'end_location' => 'nullable|string',

            'break_duration' => 'nullable|integer',
            'total_minutes' => 'nullable|integer',
        ]);

        $calculated = $payroll->calculate($data, Auth::user());

        $shift = DB::transaction(function () use ($data, $calculated) {
            $user = User::whereKey(Auth::id())->lockForUpdate()->first();
            abort_unless($user?->is_active, 403);
            $newer = WorkShift::where('user_id', $user->id)->where('calculation_version', 2)
                ->where(function ($query) use ($data) {
                    $query->whereDate('shift_date', $data['shift_date']);
                    $query->orWhere(fn ($absence) => $absence->where('type', 'deviation')
                        ->where('started_at', '<', Carbon::parse($data['shift_date'])->addDay()->format('Y-m-d H:i:s'))
                        ->where('ended_at', '>', Carbon::parse($data['shift_date'])->startOfDay()->format('Y-m-d H:i:s')));
                    if (! empty($data['started_at']) && ! empty($data['ended_at'])) {
                        $query->orWhere(fn ($q) => $q->where('started_at', '<', Carbon::parse($data['ended_at'])->format('Y-m-d H:i:s'))
                            ->where('ended_at', '>', Carbon::parse($data['started_at'])->format('Y-m-d H:i:s')));
                    }
                })->exists();
            abort_if($newer, 409, 'На эту дату уже есть смена нового табеля. Используйте новую карточку смены.');

            return WorkShift::updateOrCreate(
                [
                    'user_id' => Auth::id(),
                    'calculation_version' => 1,
                    'shift_date' => $data['shift_date'],
                ],
                array_merge($data, $calculated)
            );
        });

        ClickHouseService::log('work_shift.saved', $shift->id, [
            'shift_date' => $data['shift_date'],
            'type' => $data['type'],
            'route_id' => $data['route_id'] ?? null,
            'deviation_id' => $data['deviation_id'] ?? null,
        ]);

        return response()->json([
            'status' => 'success',
            'shift' => $shift->load(['route', 'deviation']),

        ]);
    }

    /**
     * Возвращает точный расчёт preview без сохранения (чтобы фронтенд всегда показывал то же, что сохранится).
     * Использует тот же PayrollService, что и store.
     */
    public function preview(Request $request, PayrollService $payroll)
    {
        $data = $request->validate([
            'shift_date' => 'required|date',
            'type' => 'required|in:work,deviation',
            'deviation_id' => 'nullable|exists:deviations_catalog,id',
            'route_id' => 'nullable|exists:routes_catalog,id',
            'started_at' => 'nullable|date',
            'ended_at' => 'nullable|date',
            'break_duration' => 'nullable|integer',
        ]);

        $calculated = $payroll->calculate($data, Auth::user());

        // Обогащаем ответ в том же формате, который ожидает фронтендский preview
        $response = [
            'label' => $calculated['type'] === 'work' ? 'Рабочая смена' : 'Отвлечение',
            'hours' => $calculated['hours'] ?? 0,
            'money' => $calculated['earnings'] ?? 0,
            'routeNumber' => '—',
            'tasks' => 'Отвлечение от работы по графику депо',
        ];

        if ($calculated['type'] === 'work' && ! empty($data['route_id'])) {
            $route = RoutesCatalog::find($data['route_id']);
            if ($route) {
                $response['routeNumber'] = $route->route_number;
                $response['tasks'] = $route->technological_tasks ?? 'План работ не задан';
            }
        } elseif ($calculated['type'] === 'deviation' && ! empty($data['deviation_id'])) {
            $dev = DeviationsCatalog::find($data['deviation_id']);
            if ($dev) {
                $response['label'] = $dev->name;
            }
        }

        return response()->json($response);
    }
}
