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
    ];

    protected $casts = [
        'plan_date' => 'date',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function crew()
    {
        return $this->belongsTo(Crew::class);
    }
}
