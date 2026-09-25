<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NaryadAssignment extends Model
{
    protected $fillable = [
        'user_id',
        'plan_date',
        'route_number',   // финальный номер маршрута, который проставил нарядчик
        'crew_id',
        'position',       // driver / pomoshnik (опционально)
        'start_location',
        'start_time',
        'end_location',
        'end_time',
        'notes',
        'assigned_by',
        'arm_shift_breakdown_id',
        'work_code',
        'shift_code',
        'hours_total',
        'hours_line',
        'hours_reserve',
        'hours_night',
        'hours_night_reserve',
        'hours_evening',
        'hours_evening_reserve',
        'hours_break',
        'hours_break_reserve',
        'hours_holiday',
        'hours_holiday_reserve',
        'hours_overtime',
        'two_person',
        'hours_total_2',
        'hours_line_2',
        'hours_reserve_2',
        'hours_night_2',
        'hours_night_2_reserve',
        'hours_evening_2',
        'hours_evening_2_reserve',
        'hours_break_2',
        'hours_break_2_reserve',
        'hours_holiday_2',
        'hours_holiday_2_reserve',
    ];

    protected $casts = [
        'plan_date' => 'date',
        'hours_total' => 'float',
        'hours_line' => 'float',
        'hours_reserve' => 'float',
        'hours_night' => 'float',
        'hours_night_reserve' => 'float',
        'hours_evening' => 'float',
        'hours_evening_reserve' => 'float',
        'hours_break' => 'float',
        'hours_break_reserve' => 'float',
        'hours_holiday' => 'float',
        'hours_holiday_reserve' => 'float',
        'hours_overtime' => 'float',
        'two_person' => 'boolean',
        'hours_total_2' => 'float',
        'hours_line_2' => 'float',
        'hours_reserve_2' => 'float',
        'hours_night_2' => 'float',
        'hours_night_2_reserve' => 'float',
        'hours_evening_2' => 'float',
        'hours_evening_2_reserve' => 'float',
        'hours_break_2' => 'float',
        'hours_break_2_reserve' => 'float',
        'hours_holiday_2' => 'float',
        'hours_holiday_2_reserve' => 'float',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function crew()
    {
        return $this->belongsTo(Crew::class);
    }

    public function breakdown()
    {
        return $this->belongsTo(ArmShiftBreakdown::class, 'arm_shift_breakdown_id');
    }
}
