<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserFormularLog extends Model
{
    protected $fillable = [
        'user_id',
        'formular_task_id',
        'completed_at',
        'entry_text',
        'notes',
    ];

    protected $casts = [
        'completed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(FormularTask::class, 'formular_task_id');
    }
}
