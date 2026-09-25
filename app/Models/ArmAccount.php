<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ArmAccount extends Model
{
    protected $fillable = [
        'user_id',
        'year_month',
        'kind',
        'position_code',
        'formula_kind',
        'totals',
        'status',
    ];

    protected $casts = [
        'totals' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ArmAccountLine::class)->orderBy('sort');
    }
}
