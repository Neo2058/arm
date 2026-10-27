<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkTimeMonthSettings extends Model
{
    protected $attributes = ['revision' => 1];

    protected $fillable = ['user_id', 'month', 'settings'];

    protected $casts = ['month' => 'date', 'settings' => 'array', 'revision' => 'integer'];
}
