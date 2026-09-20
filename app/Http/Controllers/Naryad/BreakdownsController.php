<?php

namespace App\Http\Controllers\Naryad;

use App\Http\Controllers\Controller;
use App\Models\ArmHoliday;
use App\Models\ArmShiftBreakdown;
use App\Models\ScheduleType;
use App\Services\ClickHouseService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;

class BreakdownsController extends Controller implements HasMiddleware
{
    use EnsuresDispatcher;

    public function partialBreakdowns(Request $request)
    {
        $this->abortIfNotDispatcher();

        $query = ArmShiftBreakdown::query()
            ->with('scheduleType')
            ->orderBy('graph_code')
            ->orderBy('route_code')
            ->orderBy('shift_code')
            ->orderBy('sequence');

        if ($request->filled('graph')) {
            $query->where('graph_code', (string) $request->string('graph')->trim());
        }
        if ($request->filled('route')) {
            $query->where('route_code', (string) $request->string('route')->trim());
        }
        if ($request->filled('shift')) {
            $query->where('shift_code', (string) $request->string('shift')->trim());
        }

        $breakdowns = $query->limit(150)->get();
        $graphs = ScheduleType::query()
            ->whereNotNull('foxpro_code')
            ->orderBy('foxpro_code')
            ->get(['id', 'name', 'foxpro_code']);

        return view('naryad.partials.breakdowns', [
            'breakdowns' => $breakdowns,
            'graphs' => $graphs,
            'filters' => [
                'graph' => $request->string('graph')->toString(),
                'route' => $request->string('route')->toString(),
                'shift' => $request->string('shift')->toString(),
            ],
            'total' => ArmShiftBreakdown::count(),
        ]);
    }

    public function updateBreakdown(Request $request, ArmShiftBreakdown $breakdown)
    {
        $this->abortIfNotDispatcher();

        $data = $request->validate([
            'start_hours' => 'nullable|numeric',
            'end_hours' => 'nullable|numeric',
            'hours_total' => 'nullable|numeric',
            'hours_line' => 'nullable|numeric',
            'hours_reserve' => 'nullable|numeric',
            'hours_night' => 'nullable|numeric',
            'hours_evening' => 'nullable|numeric',
            'hours_break' => 'nullable|numeric',
        ]);

        $breakdown->update($data);

        ClickHouseService::log('naryad.breakdown.updated', $breakdown->id, [
            'route' => $breakdown->route_code,
            'shift' => $breakdown->shift_code,
        ]);

        return response()->json(['success' => true]);
    }

    public function partialHolidays()
    {
        $this->abortIfNotDispatcher();

        $holidays = ArmHoliday::query()->orderBy('holiday_date')->get();

        return view('naryad.partials.holidays', [
            'holidays' => $holidays,
        ]);
    }

    public function storeHoliday(Request $request)
    {
        $this->abortIfNotDispatcher();

        $data = $request->validate([
            'holiday_date' => 'required|date|unique:arm_holidays,holiday_date',
            'name' => 'nullable|string|max:80',
        ]);

        $holiday = ArmHoliday::create($data);

        ClickHouseService::log('naryad.holiday.created', $holiday->id, [
            'date' => $holiday->holiday_date->toDateString(),
        ]);

        return response()->json(['success' => true]);
    }

    public function destroyHoliday(ArmHoliday $holiday)
    {
        $this->abortIfNotDispatcher();

        $id = $holiday->id;
        $date = $holiday->holiday_date?->toDateString();
        $holiday->delete();

        ClickHouseService::log('naryad.holiday.deleted', $id, ['date' => $date]);

        return response()->json(['success' => true]);
    }
}
