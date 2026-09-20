<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ArmPersonnel extends Model
{
    protected $table = 'arm_personnel';

    protected $fillable = [
        'user_id',
        'tab_number',
        'full_name',
        'hired_on',
        'fired_on',
        'position_code',
        'class_code',
        'phone_primary',
        'phone_secondary',
        'seniority_on',
        'brigade_code',
        'is_brigadier',
        'shop_code',
        'med_from',
        'med_to',
        'assistant_seniority_on',
        'roster_number',
        'assistant_seniority_years',
        'early_windows',
        'late_windows',
        'brigadier_from',
        'brigadier_to',
        'category_code',
        'premium_flag',
        'main_tab_number',
        'depo_code',
    ];

    protected $casts = [
        'hired_on' => 'date',
        'fired_on' => 'date',
        'seniority_on' => 'date',
        'med_from' => 'date',
        'med_to' => 'date',
        'assistant_seniority_on' => 'date',
        'brigadier_from' => 'date',
        'brigadier_to' => 'date',
        'is_brigadier' => 'boolean',
        'assistant_seniority_years' => 'integer',
        'early_windows' => 'array',
        'late_windows' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(ArmAppointment::class, 'user_id', 'user_id');
    }

    public function absences(): HasMany
    {
        return $this->hasMany(ArmAbsence::class, 'user_id', 'user_id');
    }

    public function isFired(): bool
    {
        return $this->fired_on !== null && $this->fired_on->lt(now()->startOfDay());
    }
}
