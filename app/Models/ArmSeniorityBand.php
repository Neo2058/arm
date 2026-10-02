<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ArmSeniorityBand extends Model
{
    protected $fillable = [
        'years_from',
        'years_to',
        'percent',
    ];

    protected $casts = [
        'years_from' => 'integer',
        'years_to' => 'integer',
        'percent' => 'float',
    ];
}
