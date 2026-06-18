<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JournalVacation extends Model
{
    protected $fillable = [
        'user_id', 'instructor_id', 'column', 'start_date', 'end_date', 'type', 'notes',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function instructor()
    {
        return $this->belongsTo(User::class, 'instructor_id');
    }
}
