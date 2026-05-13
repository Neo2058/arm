<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Quiz extends Model
{
    // Здесь должны быть поля именно таблицы QUIZZES
    protected $fillable = [
        'title',
        'description',
        'document_id',
        'time_limit',
        'is_active'
    ];

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
