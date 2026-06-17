<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RoutesCatalog extends Model
{
    // Указываем имя таблицы, если Laravel пытается искать во множественном числе (routes_catalogs)
    protected $table = 'routes_catalog';

    protected $fillable = [
        'schedule_type_id',
        'route_number',
        'shift_type',
        'night_parity',
        'from_night',
        'start_location',
        'default_start_time',
        'end_location',
        'default_end_time',
        'default_break_duration',
        'technological_tasks'
    ];

    public function scheduleType()
    {
        return $this->belongsTo(ScheduleType::class);
    }
}
