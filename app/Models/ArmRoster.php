<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArmRoster extends Model
{
    public const KIND_PRIMARY = 'primary';

    public const KIND_EXTRA = 'extra';

    protected $fillable = [
        'roster_date',
        'kind',
        'route_number',
        'instrument',
    ];

    protected $casts = [
        'roster_date' => 'date',
    ];

    public function isPrimary(): bool
    {
        return $this->kind === self::KIND_PRIMARY;
    }

    public function routeCode(): BelongsTo
    {
        return $this->belongsTo(ArmCode::class, 'route_number', 'code')
            ->where('book_code', '43');
    }
}
