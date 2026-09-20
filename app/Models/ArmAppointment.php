<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArmAppointment extends Model
{
    protected $fillable = [
        'user_id',
        'tab_number',
        'appointed_on',
        'position_code',
        'class_code',
    ];

    protected $casts = [
        'appointed_on' => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
