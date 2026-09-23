<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArmDayAdjustment extends Model
{
    protected $fillable = [
        'user_id',
        'tab_number',
        'plan_date',
        'route_code',
        'shift_code',
    ];

    protected $casts = [
        'plan_date' => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
