<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DisciplineCard extends Model
{
    protected $guarded = [];

    protected $casts = ['due_date' => 'date', 'require_response' => 'boolean', 'require_report_file' => 'boolean', 'require_explanation_file' => 'boolean'];

    public function author()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assignments()
    {
        return $this->hasMany(DisciplineAssignment::class, 'card_id');
    }
}
