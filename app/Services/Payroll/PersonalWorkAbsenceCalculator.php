<?php

namespace App\Services\Payroll;

use App\Models\WorkShift;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;

/** Calendar accounting only. Zero stored subtotal never means an unpaid entitlement. */
class PersonalWorkAbsenceCalculator
{
    public const LABELS = ['sick' => 'Больничный', 'leave' => 'Отпуск', 'study' => 'Учёба'];

    public function input(array $input): array
    {
        $data = Validator::make($input, [
            'type' => 'required|in:deviation', 'absence_kind' => 'required|in:sick,leave,study',
            'shift_date' => 'required|date_format:Y-m-d',
            'absence_end' => 'required|date_format:Y-m-d|after_or_equal:shift_date',
        ])->validate();
        $start = CarbonImmutable::parse($data['shift_date'], 'Europe/Moscow');
        $end = CarbonImmutable::parse($data['absence_end'], 'Europe/Moscow')->addDay();
        Validator::make(['days' => $start->diffInDays($end)], ['days' => 'numeric|min:1|max:366'])->validate();

        return $data + ['started_at' => $start->format('Y-m-d H:i:s'), 'ended_at' => $end->format('Y-m-d H:i:s')];
    }

    public function calculate(WorkShift $shift): array
    {
        $input = $this->input(['type' => $shift->type, 'absence_kind' => $shift->absence_kind,
            'shift_date' => $shift->shift_date?->toDateString(), 'absence_end' => $shift->absence_end?->toDateString()]);
        $shift->started_at = $input['started_at'];
        $shift->ended_at = $input['ended_at'];
        $shift->break_duration = 0;
        $shift->segments = null;
        $shift->route_id = null;
        $shift->deviation_id = null;
        $shift->shift_code = null;
        $shift->start_location = null;
        $shift->end_location = null;

        return ['version' => 2, 'kind' => 'absence', 'absence_kind' => $shift->absence_kind,
            'paid_minutes' => 0, 'subtotal_kopecks' => 0, 'calculation_complete' => false,
            'payment_status' => 'not_calculated', 'pending' => ['absence_payment_rules']];
    }
}
