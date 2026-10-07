<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ArmSignature extends Model
{
    protected $fillable = [
        'sort_order',
        'position',
        'full_name',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];
}
