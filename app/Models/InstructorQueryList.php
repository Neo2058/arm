<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstructorQueryList extends Model
{
    protected $fillable = [
        'user_id',
        'original_name',
        'path',
        'queries',
    ];

    protected $casts = [
        'queries' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
