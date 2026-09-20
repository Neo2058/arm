<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ArmShiftBreakdown extends Model
{
    protected $fillable = [
        'schedule_type_id',
        'graph_code',
        'route_code',
        'shift_code',
        'sequence',
        'position_code',
        'start_hours',
        'end_hours',
        'hours_total',
        'hours_line',
        'hours_line_2',
        'hours_reserve',
        'hours_reserve_2',
        'hours_night',
        'hours_night_reserve',
        'hours_night_2',
        'hours_night_2_reserve',
        'hours_evening',
        'hours_evening_reserve',
        'hours_evening_2',
        'hours_evening_2_reserve',
        'hours_break',
        'hours_break_reserve',
        'hours_break_2',
        'hours_break_2_reserve',
        'appearance_start',
        'appearance_end',
        'content',
        'morning_code',
        'morning_shift',
        'notes',
    ];

    protected $casts = [
        'start_hours' => 'float',
        'end_hours' => 'float',
        'hours_total' => 'float',
        'hours_line' => 'float',
        'hours_line_2' => 'float',
        'hours_reserve' => 'float',
        'hours_reserve_2' => 'float',
        'hours_night' => 'float',
        'hours_night_reserve' => 'float',
        'hours_night_2' => 'float',
        'hours_night_2_reserve' => 'float',
        'hours_evening' => 'float',
        'hours_evening_reserve' => 'float',
        'hours_evening_2' => 'float',
        'hours_evening_2_reserve' => 'float',
        'hours_break' => 'float',
        'hours_break_reserve' => 'float',
        'hours_break_2' => 'float',
        'hours_break_2_reserve' => 'float',
    ];

    public function scheduleType(): BelongsTo
    {
        return $this->belongsTo(ScheduleType::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(NaryadAssignment::class, 'arm_shift_breakdown_id');
    }

    /**
     * Часовые поля 1 лица для записи в naryad_assignments.
     *
     * @return array<string, mixed>
     */
    public function toAssignmentHours(): array
    {
        return [
            'arm_shift_breakdown_id' => $this->id,
            'shift_code' => $this->shift_code !== '' ? $this->shift_code : null,
            'hours_total' => $this->hours_total,
            'hours_line' => $this->hours_line,
            'hours_reserve' => $this->hours_reserve,
            'hours_night' => $this->hours_night,
            'hours_night_reserve' => $this->hours_night_reserve,
            'hours_evening' => $this->hours_evening,
            'hours_evening_reserve' => $this->hours_evening_reserve,
            'hours_break' => $this->hours_break,
            'hours_break_reserve' => $this->hours_break_reserve,
        ];
    }
}
