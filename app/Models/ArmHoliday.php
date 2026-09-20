<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ArmHoliday extends Model
{
    protected $fillable = [
        'holiday_date',
        'name',
    ];

    protected $casts = [
        'holiday_date' => 'date',
    ];
}
