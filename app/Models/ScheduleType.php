<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScheduleType extends Model
{
    protected $fillable = [
        'name',
        'foxpro_code',
        'routes_count',       // сколько маршрутов в сутки по этому типу
        'people_per_route',   // сколько человек на один маршрут
        'description',
        'is_default',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'routes_count' => 'integer',
        'people_per_route' => 'integer',
    ];
}
