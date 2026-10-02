<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ArmTariff extends Model
{
    protected $fillable = [
        'code',
        'name',
        'rate',
    ];

    protected $casts = [
        'rate' => 'float',
    ];
}
