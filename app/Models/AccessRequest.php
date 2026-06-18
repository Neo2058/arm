<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccessRequest extends Model
{
    protected $fillable = [
        'tab_number',
        'fio',
        'ip_address',
        'user_agent',
        'status',
        'notes',
    ];

    protected $casts = [
        'status' => 'string',
    ];
}
