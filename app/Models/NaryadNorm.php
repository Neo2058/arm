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
    ];

    public function extraConditions()
    {
        return $this->hasMany(NaryadExtraCondition::class);
    }
}
