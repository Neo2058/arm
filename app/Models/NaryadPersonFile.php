<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NaryadPersonFile extends Model
{
    protected $fillable = [
        'naryad_id',
        'user_id',
        'fio',
        'file_name',
        'file_path',
        'hits_count',
    ];

    public function naryad(): BelongsTo
    {
        return $this->belongsTo(Naryad::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
};
