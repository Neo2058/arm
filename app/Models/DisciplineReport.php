<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DisciplineReport extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['requirements' => 'array', 'submitted_at' => 'datetime', 'reviewed_at' => 'datetime'];

    public function assignment()
    {
        return $this->belongsTo(DisciplineAssignment::class, 'assignment_id');
    }

    public function files()
    {
        return $this->hasMany(DisciplineFile::class, 'report_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
