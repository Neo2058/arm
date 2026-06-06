<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeviationsCatalog;
use Carbon\Carbon;
use Illuminate\Http\Request;
use App\Models\WorkShift;
use App\Models\RoutesCatalog;
use Illuminate\Support\Facades\Auth;
use App\Services\Payroll\PayrollService;

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
            ->whereBetween('shift_date', [$start, $end])
            ->with([
                'route:id,route_number,start_location,end_location,default_start_time,default_end_time,default_break_duration,technological_tasks', 'deviation'
            ])
            ->get();

        // Отдаем полный каталог маршрутов для выпадающего списка
        $catalog = RoutesCatalog::all();
        // ПОДГРУЖАЕМ СПРАВОЧНИК ОТВЛЕЧЕНИЙ ДЛЯ ФРОНТЕНДА
        $deviationsCatalog = DeviationsCatalog::all();

        return response()->json([
            'shifts' => $shifts,
            'catalog' => $catalog,
            'deviations_catalog' => $deviationsCatalog // <-- Улетело в React
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

        $shift = WorkShift::updateOrCreate(
            [
                'user_id' => Auth::id(),
                'shift_date' => $data['shift_date']
            ],
            array_merge($data, $calculated)
        );

        return response()->json([
            'status' => 'success',
            'shift' => $shift->load(['route', 'deviation'])

        ]);
    }
}
