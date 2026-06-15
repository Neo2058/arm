<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrainingMaterial extends Model
{
    use HasFactory;

    protected $fillable = [
        'training_topic_id',
        'title',
        'description',
        'type',           // text, video, audio, document, quiz
        'content',        // текстовый контент / выжимка (для type = text)
        'file_path',      // путь в хранилище (MinIO/S3)
        'file_name',
        'mime_type',
        'duration',       // длительность в секундах (для аудио/видео)
        'is_active',
        'sort_order',
        'metadata',       // JSON для дополнительных данных
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'metadata'  => 'array',
    ];

    public function topic(): BelongsTo
    {
        return $this->belongsTo(TrainingTopic::class, 'training_topic_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function getFileUrlAttribute(): ?string
    {
        if (!$this->file_path) {
            return null;
        }

        // Предполагаем использование диска 's3' (MinIO)
        return \Storage::disk('s3')->url($this->file_path);
    }
}
