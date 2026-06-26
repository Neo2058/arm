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
     * Генерирует временную подписанную ссылку на потоковое воспроизведение через приложение.
     * Использует signed route + прокси для защиты (inline, no direct S3).
     * Это обеспечивает временную ссылку и усложняет скачивание.
     *
     * @param int $minutes Время жизни ссылки.
     */
    public function getTemporaryUrl(int $minutes = 15): ?string
    {
        if (!$this->file_path) {
            return null;
        }

        try {
            $routeName = $this->type === 'video' ? 'training.video.stream' : 'training.audio.stream';
            return \Illuminate\Support\Facades\URL::temporarySignedRoute(
                $routeName,
                now()->addMinutes($minutes),
                $this
            );
        } catch (\Throwable $e) {
            \Log::warning('Failed to generate stream URL for TrainingMaterial', [
                'id' => $this->id,
                'file_path' => $this->file_path,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Аксессор для Blade ({{ $material->file_url }}).
     * Возвращает временную подписанную ссылку на стрим (15 мин).
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
