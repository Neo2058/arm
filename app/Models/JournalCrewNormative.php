<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JournalCrewNormative extends Model
{
    protected $fillable = [
        'user_id', 'instructor_id', 'column', 'type', 'last_date', 'next_date', 'class',
    ];

    protected $casts = [
        'last_date' => 'date',
        'next_date' => 'date',
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
