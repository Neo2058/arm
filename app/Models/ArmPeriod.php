<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArmPeriod extends Model
{
    protected $fillable = [
        'year_month',
        'status',
        'closed_at',
        'closed_by',
        'notes',
    ];

    protected $casts = [
        'closed_at' => 'datetime',
    ];

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function isClosed(): bool
    {
        return $this->status === 'closed';
    }

    public static function closedMonth(string $yearMonth): bool
    {
        return static::query()
            ->where('year_month', $yearMonth)
            ->where('status', 'closed')
            ->exists();
    }
}
