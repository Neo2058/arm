<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserTrainingProgress extends Model
{
    use HasFactory;

    protected $table = 'user_training_progress';

    protected $fillable = [
        'user_id',
        'training_material_id',
        'status',           // not_started, in_progress, completed
        'progress_percent',
        'last_viewed_at',
        'completed_at',
        'notes',
    ];

    protected $casts = [
        'last_viewed_at' => 'datetime',
        'completed_at'   => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(TrainingMaterial::class, 'training_material_id');
    }
}
