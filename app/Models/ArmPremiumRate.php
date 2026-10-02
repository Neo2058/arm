<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ArmPremiumRate extends Model
{
    protected $fillable = [
        'position_code',
        'is_brigadier',
        'percent',
        'name',
    ];

    protected $casts = [
        'is_brigadier' => 'boolean',
        'percent' => 'float',
    ];
}
