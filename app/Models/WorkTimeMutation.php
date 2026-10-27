<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkTimeMutation extends Model
{
    protected $guarded = [];

    protected $casts = ['response' => 'array'];
}
