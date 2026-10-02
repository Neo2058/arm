<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArmMonthPremium extends Model
{
    protected $table = 'arm_month_premiums';

    protected $fillable = [
        'year_month',
        'user_id',
        'tab_number',
        'position_code',
        'percent_plan',
        'percent_fact',
        'ktu',
        'note',
    ];

    protected $casts = [
        'percent_plan' => 'float',
        'percent_fact' => 'float',
        'ktu' => 'float',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
