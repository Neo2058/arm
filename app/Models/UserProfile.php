<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserProfile extends Model
{
    protected $fillable = [
        'user_id', 'tab_number', 'column', 'instructor',
        'position', 'phoneNumber', 'driverRoot', 'dateRoot', 'birth_date'
    ];

}
