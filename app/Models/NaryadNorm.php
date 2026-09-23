<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NaryadNorm extends Model
{
    protected $fillable = [
        'year_hours',
        'month_hours',
        'week_hours',
        'min_rest_hours',
        'monthly_hours', // json per month e.g. {"2026-06": 160}
        'planir',
    ];

    protected $casts = [
        'monthly_hours' => 'array',
        'planir' => 'array',
    ];

    public function extraConditions()
    {
        return $this->hasMany(NaryadExtraCondition::class);
    }
}
