<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RouteVariant extends Model
{
    protected $fillable = [
        'effective_route',     // то, что увидит нарядчик и проставит (25, 36 и т.д.)
        'schedule_type_id',    // тип графика
        'shift_type',          // тип смены: 1,2,3,3+,4+,5+
        'context',             // morning | night | any (legacy)
        'base_route_number',   // исходный номер (опционально)
        'route_catalog_id',    // связь с основным каталогом
        'start_location',
        'start_time',
        'end_location',
        'end_time',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function catalogRoute()
    {
        return $this->belongsTo(\App\Models\RoutesCatalog::class, 'route_catalog_id');
    }

    public function scheduleType()
    {
        return $this->belongsTo(ScheduleType::class);
    }
}
