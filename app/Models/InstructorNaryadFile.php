<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstructorNaryadFile extends Model
{
    protected $fillable = [
        'user_id',
        'original_name',
        'path',
        'naryad_date',
        'weekday',
        'weekend',
        'even_day',
        'meta',
        'assignments',
    ];

    protected $casts = [
        'naryad_date' => 'date',
        'weekend' => 'boolean',
        'even_day' => 'boolean',
        'meta' => 'array',
        'assignments' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
