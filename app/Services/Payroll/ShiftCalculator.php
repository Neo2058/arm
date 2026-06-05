<?php

namespace App\Services\Payroll;
use Carbon\Carbon;

class ShiftCalculator
{
    public function calculate(array $shift): array
    {
        $start = Carbon::parse($shift['started_at']);
        $end = Carbon::parse($shift['ended_at']);

        $minutes = $start->diffInMinutes($end);
        $break = $shift['break_duration'] ?? 0;

        $netMinutes = max($minutes - $break, 0);
        $hours = $netMinutes / 60;

        return [
            'minutes' => $netMinutes,
            'hours' => $hours,
        ];
    }
}
