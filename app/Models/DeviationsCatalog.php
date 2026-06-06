<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeviationsCatalog extends Model
{
    protected $table = 'deviations_catalog';
    protected $fillable = ['name', 'sys_key', 'hourly_rate', 'default_minutes'];
}
