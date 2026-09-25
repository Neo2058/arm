<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArmAccountLine extends Model
{
    protected $fillable = [
        'arm_account_id',
        'sort',
        'name',
        'pay_code',
        'tariff_code',
        'tariff',
        'percent',
        'hours',
        'cost_code',
        'profession',
        'ls_number',
        'formula_id',
    ];

    protected $casts = [
        'sort' => 'integer',
        'tariff' => 'float',
        'percent' => 'float',
        'hours' => 'float',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(ArmAccount::class, 'arm_account_id');
    }

    public function formula(): BelongsTo
    {
        return $this->belongsTo(ArmPayFormula::class, 'formula_id');
    }
}
