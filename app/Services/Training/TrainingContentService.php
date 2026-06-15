<?php

namespace App\Services\Training;

use App\Models\TrainingMaterial;
use App\Models\TrainingTopic;
use App\Models\User;
use App\Services\TelegramService;
use App\Services\ClickHouseService;
use Illuminate\Support\Facades\Log;

class TrainingContentService
{
    /**
     * Получить все доступные темы обучения.
     * В будущем здесь может быть фильтрация по ролям пользователя.
     */
    public function getAllTopics(): \Illuminate\Database\Eloquent\Collection
    {
        return TrainingTopic::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get(['id', 'title', 'description', 'slug']);
    }

    /**
     * Получить материалы по теме.
     */
    public function getMaterialsByTopic(int $topicId): \Illuminate\Database\Eloquent\Collection
    {
        return TrainingMaterial::query()
            ->where('training_topic_id', $topicId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get([
                'id',
                'training_topic_id',
                'title',
                'description',
                'type',           // text, video, audio, document
                'content',        // текстовая выжимка (для type=text)
                'file_path',      // путь в MinIO/S3 (для видео/аудио)
                'file_name',
                'duration',       // для аудио/видео
                'sort_order',
            ]);
    }

    /**
     * Отправить материал пользователю в Telegram.
     * Поддерживает разные типы контента.
     */
    public function sendMaterialToUser(int $chatId, int $materialId, ?User $user = null): bool
    {
        $material = TrainingMaterial::find($materialId);

        if (!$material || !$material->is_active) {
            TelegramService::send("Материал не найден или недоступен.", $chatId);
            return false;
        }

        $topic = $material->topic;

        $caption = "📚 <b>{$material->title}</b>";
        if ($topic) {
            $caption .= "\nТема: <i>{$topic->title}</i>";
        }
        if ($material->description) {
            $caption .= "\n\n" . $material->description;
        }

        try {
            switch ($material->type) {
                case 'text':
                    $message = $caption . "\n\n" . ($material->content ?? '');
                    TelegramService::send($message, $chatId, 'HTML');
                    break;

                case 'video':
                    if ($material->file_path) {
                        TelegramService::sendVideo(
                            $material->file_path,
                            $chatId,
                            $caption,
                            'HTML',
                            $material->file_name
                        );
                    } else {
                        TelegramService::send($caption . "\n\n[Видео временно недоступно]", $chatId, 'HTML');
                    }
                    break;

                case 'audio':
                    if ($material->file_path) {
                        TelegramService::sendAudio(
                            $material->file_path,
                            $chatId,
                            $caption,
                            'HTML',
                            $material->file_name
                        );
                    } else {
                        TelegramService::send($caption . "\n\n[Аудио временно недоступно]", $chatId, 'HTML');
                    }
                    break;

                case 'document':
                    if ($material->file_path) {
                        TelegramService::sendDocument(
                            $material->file_path,
                            $chatId,
                            $caption,
                            'HTML',
                            $material->file_name
                        );
                    } else {
                        TelegramService::send($caption . "\n\n[Документ временно недоступно]", $chatId, 'HTML');
                    }
                    break;

                default:
                    TelegramService::send($caption, $chatId, 'HTML');
            }

            // Логируем действие пользователя
            ClickHouseService::log('training_material_viewed', $material->id, [
                'user_id'    => $user?->id,
                'chat_id'    => $chatId,
                'material_id' => $material->id,
                'type'       => $material->type,
                'topic_id'   => $material->training_topic_id,
            ]);

            // В будущем здесь можно обновлять прогресс пользователя:
            // UserTrainingProgress::updateOrCreate([...]);

            return true;

        } catch (\Throwable $e) {
            Log::error('TrainingContentService: Failed to send material', [
                'material_id' => $materialId,
                'chat_id'     => $chatId,
                'error'       => $e->getMessage(),
            ]);

            TelegramService::send("Произошла ошибка при отправке материала. Попробуйте позже.", $chatId);
            return false;
        }
    }

    /**
     * Получить список тем с кратким описанием для меню в боте.
     * Удобно для генерации inline-кнопок.
     */
    public function getTopicsForBotMenu(): array
    {
        return $this->getAllTopics()
            ->map(fn ($topic) => [
                'id'          => $topic->id,
                'title'       => $topic->title,
                'description' => $topic->description,
                'slug'        => $topic->slug,
            ])
            ->toArray();
    }

    /**
     * Найти материал по ID (для обработки callback'ов из бота).
     */
    public function findMaterial(int $id): ?TrainingMaterial
    {
        return TrainingMaterial::where('is_active', true)->find($id);
    }
}
