<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Crew extends Model
{
    protected $fillable = [
        'member1_tab',
        'member2_tab',
        'type',           // t5 or t6
        'label',          // e.g. "270012 - 270011"
        'is_active',
        'notes',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected static function booted()
    {
        static::saving(function ($crew) {
            if (empty($crew->label) && $crew->member1_tab && $crew->member2_tab) {
                $crew->label = trim($crew->member1_tab) . ' - ' . trim($crew->member2_tab);
            }
        });
    }
}
