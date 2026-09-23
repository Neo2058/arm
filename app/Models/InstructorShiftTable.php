<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstructorShiftTable extends Model
{
    protected $fillable = [
        'user_id',
        'kind',
        'original_name',
        'path',
        'entries',
    ];

    protected $casts = [
        'entries' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
