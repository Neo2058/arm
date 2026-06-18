<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JournalTask extends Model
{
    protected $fillable = [
        'user_id', 'column', 'title', 'description', 'source', 'status', 'due_date'
    ];

    protected $casts = [
        'due_date' => 'date',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
