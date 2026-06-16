<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NaryadQuota extends Model
{
    protected $fillable = [
        'plan_date',
        'schedule_type_id',
        'required_crews',
        'notes',
    ];

    protected $casts = [
        'plan_date' => 'date',
    ];

    public function scheduleType()
    {
        return $this->belongsTo(ScheduleType::class);
    }
}
