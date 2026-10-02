<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArmExtraPay extends Model
{
    protected $fillable = [
        'year_month',
        'user_id',
        'tab_number',
        'full_name',
        'position_code',
        'hours_tech',
        'tech_on',
        'hours_accident',
        'accident_on',
        'hours_med',
        'med_on',
        'extra_days_off',
        'extra_hours_off',
    ];

    protected $casts = [
        'hours_tech' => 'float',
        'tech_on' => 'date',
        'hours_accident' => 'float',
        'accident_on' => 'date',
        'hours_med' => 'float',
        'med_on' => 'date',
        'extra_days_off' => 'integer',
        'extra_hours_off' => 'float',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
