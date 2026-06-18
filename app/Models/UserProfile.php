<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserProfile extends Model
{
    protected $fillable = [
        'user_id', 'tab_number', 'column', 'instructor',
        'position', 'phoneNumber', 'driverRoot', 'dateRoot', 'birth_date',
        // Поля для расширенного справочника планирования наряда (для нарядчика)
        'is_brigadir', 'can_manage_t6', 'can_maneuvers', 'is_pomoshnik', 'additional_notes',
        // For journal TЧМ
        'normative_class', 'is_maneuver', 'is_t6'
    ];

}
