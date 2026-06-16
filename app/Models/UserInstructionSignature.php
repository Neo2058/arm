<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserInstructionSignature extends Model
{
    protected $fillable = [
        'user_id',
        'document_id',
        'instruction_category_id',
        'signed_at',
        'notes',
    ];

    protected $casts = [
        'signed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(InstructionCategory::class, 'instruction_category_id');
    }
}
