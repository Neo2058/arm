<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DisciplineFile extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    public function report()
    {
        return $this->belongsTo(DisciplineReport::class, 'report_id');
    }
}
