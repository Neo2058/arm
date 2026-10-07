<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ArmCodeBook extends Model
{
    protected $fillable = [
        'kodspr',
        'dbfspr',
        'nsisr',
        'nazspr',
        'dlkodel',
        'dlnazel',
    ];

    protected $casts = [
        'dlkodel' => 'integer',
        'dlnazel' => 'integer',
    ];

    public function getRouteKeyName(): string
    {
        return 'kodspr';
    }

    public function codes(): HasMany
    {
        return $this->hasMany(ArmCode::class, 'book_code', 'kodspr');
    }

    public function inFoxproPopup(): bool
    {
        return trim((string) $this->nsisr) !== '';
    }
}
