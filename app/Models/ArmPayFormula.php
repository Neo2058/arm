<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ArmPayFormula extends Model
{
    protected $fillable = [
        'kind',
        'nom',
        'name',
        'percent_text',
        'percent',
        'pay_code',
        'tariff_code',
        'tariff',
        'cost_code',
        'formula',
        'selected',
    ];

    protected $casts = [
        'nom' => 'integer',
        'percent' => 'float',
        'tariff' => 'float',
        'selected' => 'boolean',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(ArmAccountLine::class, 'formula_id');
    }
}
