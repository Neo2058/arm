<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PhoneContact extends Model
{
    protected $fillable = ['name', 'phone', 'created_by', 'personnel_id'];
}
