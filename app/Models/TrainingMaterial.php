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

    public function comments()
    {
        return $this->hasMany(TrainingMaterialComment::class);
    }

    public function reactions()
    {
        return $this->hasMany(TrainingMaterialReaction::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Возвращает временную (signed) ссылку на файл.
     * Никогда не возвращает прямую публичную ссылку — это требование безопасности проекта.
     *
     * @param int $minutes Срок действия ссылки в минутах. По умолчанию 15 минут.
     */
    /**
     * Генерирует временную подписанную ссылку (presigned URL).
     * Это основной и единственный способ доступа к видео/аудио (по требованиям безопасности проекта).
     *
     * @param int $minutes Время жизни ссылки. Рекомендуется 10-20 минут для медиа.
     */
    public function getTemporaryUrl(int $minutes = 15): ?string
    {
        if (!$this->file_path) {
            return null;
        }

        return \Storage::disk('s3')->temporaryUrl(
            $this->file_path,
            now()->addMinutes($minutes),
            [
                'ResponseContentDisposition' => 'inline',
                'ResponseCacheControl'       => 'no-store, no-cache, must-revalidate, max-age=0',
            ]
        );
    }

    /**
     * Аксессор для обратной совместимости.
     * Всегда отдаёт временную ссылку (15 минут).
     * Используется в Blade-шаблонах видео/аудио.
     */
    public function getFileUrlAttribute(): ?string
    {
        return $this->getTemporaryUrl(15);
    }

    public function getLikesCountAttribute(): int
    {
        return $this->reactions()->where('reaction_type', 'like')->count();
    }

    public function getDislikesCountAttribute(): int
    {
        return $this->reactions()->where('reaction_type', 'dislike')->count();
    }
}
