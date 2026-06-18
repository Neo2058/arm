<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Naryad extends Model
{
    protected $fillable = [
        'title',
        'file_path',
        'naryad_date',
        'uploaded_by',
        'allowed_roles',
    ];

    protected $casts = [
        'allowed_roles' => 'array',
        'naryad_date' => 'date',
    ];

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
