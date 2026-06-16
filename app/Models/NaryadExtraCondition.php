<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NaryadExtraCondition extends Model
{
    protected $fillable = [
        'naryad_norm_id',
        'name',
        'value',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function norm()
    {
        return $this->belongsTo(NaryadNorm::class, 'naryad_norm_id');
    }
}
