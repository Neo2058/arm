<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NaryadPodstroikaLimit extends Model
{
    protected $fillable = [
        'user_id',
        'for_month',
        'max_approved',
    ];

    protected $casts = [
        'for_month' => 'date',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
