<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Podstroika extends Model
{
    protected $fillable = [
        'user_id',
        'for_month',
        'details',
        'status',
    ];

    protected $casts = [
        'for_month' => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function profile()
    {
        return $this->hasOneThrough(UserProfile::class, User::class, 'id', 'user_id', 'user_id', 'id');
    }
}
