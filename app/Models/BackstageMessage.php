<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BackstageMessage extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'message',
        'attachment_path',
        'support_amount',
        'source',
        'yookassa_payment_id',
        'payment_status',
        'paid_at',
    ];

    protected $casts = [
        'paid_at' => 'datetime',
        'support_amount' => 'decimal:2',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
