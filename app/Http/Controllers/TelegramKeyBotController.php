<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\TelegramService;
use App\Services\ClickHouseService;
use Illuminate\Support\Facades\Log;

class TelegramKeyBotController extends Controller
{
    /**
     * Генерирует 4-значную последовательность ключа на текущий месяц.
     * Алгоритм детерминированный на основе года+месяца+соли.
     */
    public function getMonthlySequence(): array
    {
        $month = (int)date('m');
        $year  = (int)date('Y');

        // Секретная соль — обязательно вынеси в .env
        $salt = config('app.barrier_salt', 'you-never-win-in-game');

        $baseString = "{$year}-{$month}-{$salt}";
        $hash = hash('sha256', $baseString);

        return [
            hexdec(substr($hash, 0, 2))  % 4,
            hexdec(substr($hash, 2, 2))  % 4,
            hexdec(substr($hash, 4, 2))  % 4,
            hexdec(substr($hash, 6, 2))  % 4,
        ];
    }

    /**
     * Основной обработчик webhook от Telegram.
     */
    public function webhook(Request $request)
    {
        $update = $request->all();

        if (!isset($update['message'])) {
            return response()->json(['ok' => true]);
        }

        $message = $update['message'];
        $chatId  = $message['chat']['id'];
        $text    = strtolower(trim($message['text'] ?? ''));
        $fromId  = $message['from']['id'] ?? null;

        $isPrivate = ($message['chat']['type'] ?? '') === 'private';
        $isGroup   = in_array($message['chat']['type'] ?? '', ['group', 'supergroup']);

        // Логируем все входящие сообщения (полезно для отладки)
        ClickHouseService::log('telegram_message_received', 0, [
            'chat_id'   => $chatId,
            'chat_type' => $message['chat']['type'] ?? 'unknown',
            'text'      => $text,
            'from_id'   => $fromId,
        ]);

        try {
            // === /start — приветствие (работает в личке и в группе) ===
            if (str_starts_with($text, '/start')) {
                $this->handleStart($chatId, $isPrivate);
                return response()->json(['ok' => true]);
            }

            // === /key — только в группе (или в личке, если хочешь открыть) ===
            if (str_starts_with($text, '/key')) {
                // Можно раскомментировать, если хочешь разрешить /key только в группе
                // if (!$isGroup) return response()->json(['ok' => true]);

                $this->sendMonthlyKey($chatId);
                ClickHouseService::log('telegram_key_requested', 0, ['chat_id' => $chatId, 'via' => 'command']);
                return response()->json(['ok' => true]);
            }

            // === Будущие команды (заглушки для архитектуры) ===
            if (str_starts_with($text, '/training') || str_starts_with($text, '/обучение')) {
                $this->handleTrainingCommand($chatId, $text, $isPrivate);
                return response()->json(['ok' => true]);
            }

            if (str_starts_with($text, '/topic') || str_starts_with($text, '/тема')) {
                $this->handleTopicCommand($chatId, $text);
                return response()->json(['ok' => true]);
            }

            // Можно добавить обработку callback_query (кнопки) в будущем

        } catch (\Throwable $e) {
            Log::error('Telegram bot error', [
                'chat_id' => $chatId,
                'text'    => $text,
                'error'   => $e->getMessage(),
            ]);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Приветственное сообщение при /start.
     * В будущем здесь можно будет показывать меню с кнопками.
     */
    protected function handleStart(int $chatId, bool $isPrivate): void
    {
        $welcomeText = "👋 <b>Добро пожаловать в обучающего бота ТЧ-15!</b>\n\n";

        if ($isPrivate) {
            $welcomeText .= "Я помогаю с доступом к материалам и ключам.\n\n";
            $welcomeText .= "Доступные команды:\n";
            $welcomeText .= "• /key — получить ключ доступа на текущий месяц\n";
            $welcomeText .= "• /training — разделы обучения (скоро)\n";
            $welcomeText .= "• /topic — поиск по темам (в разработке)\n\n";
            $welcomeText .= "В будущем я буду выдавать выжимки из инструкций, видео- и аудиоматериалы по выбранным темам.";
        } else {
            $welcomeText .= "Здесь публикуются ежемесячные ключи доступа.\n";
            $welcomeText .= "Для личного взаимодействия напиши мне в личные сообщения.";
        }

        TelegramService::send($welcomeText, $chatId, 'HTML');
        ClickHouseService::log('telegram_start_command', 0, ['chat_id' => $chatId]);
    }

    /**
     * Отправляет текущий месячный ключ.
     * Может вызываться как по команде, так и по расписанию.
     */
    public function sendMonthlyKey(?int $chatId = null): void
    {
        $targetChat = $chatId ?? config('services.telegram.group_id');

        if (!$targetChat) {
            Log::warning('TelegramKeyBot: group_id не настроен');
            return;
        }

        $sequence  = $this->getMonthlySequence();
        $monthName = date('F Y'); // Например: "June 2026"

        $text = "🔑 <b>Ключ доступа на этот месяц ({$monthName})</b>\n\n";
        $text .= "Комбинация: <code>" . implode(' ', $sequence) . "</code>\n\n";
        $text .= "✅ Действует до конца месяца.\n";
        $text .= "Используй её для прохода через барьер.";

        TelegramService::send($text, $targetChat, 'HTML');

        ClickHouseService::log('telegram_monthly_key_published', 0, [
            'chat_id' => $targetChat,
            'month'   => date('Y-m'),
        ]);
    }

    /**
     * Заглушка для будущей команды /training.
     * Здесь в будущем будет меню с разделами обучения.
     */
    protected function handleTrainingCommand(int $chatId, string $text, bool $isPrivate): void
    {
        $msg = "📚 <b>Раздел обучения</b>\n\n";
        $msg .= "Этот функционал находится в разработке.\n";
        $msg .= "Скоро здесь появятся:\n";
        $msg .= "• Выжимки из инструкций\n";
        $msg .= "• Видео- и аудиоматериалы\n";
        $msg .= "• Тесты по темам\n\n";
        $msg .= "Следи за обновлениями!";

        TelegramService::send($msg, $chatId, 'HTML');
    }

    /**
     * Заглушка для команды по темам.
     */
    protected function handleTopicCommand(int $chatId, string $text): void
    {
        $msg = "🔍 <b>Поиск по темам</b>\n\n";
        $msg .= "Функционал выдачи материалов по конкретным темам (инструкции, видео, аудио) будет добавлен позже.\n";
        $msg .= "Архитектура уже заложена.";

        TelegramService::send($msg, $chatId, 'HTML');
    }

    /**
     * Публичный метод для планировщика (Laravel Scheduler).
     * Вызывается раз в месяц для автоматической публикации ключа.
     */
    public function publishMonthlyKey(): void
    {
        $this->sendMonthlyKey();
    }

    /**
     * Удобный метод для отправки ключа в группу (используется в scheduler).
     * Можно вызывать вручную или из команды.
     */
    public function sendMonthlyKeyToGroup(): void
    {
        $groupId = config('services.telegram.group_id');
        if ($groupId) {
            $this->sendMonthlyKey((int)$groupId);
        }
    }

    /**
     * Публикация ключа по расписанию (рекомендуется вызывать 1 числа каждого месяца).
     * Этот метод предназначен для вызова из Laravel Scheduler или Artisan команды.
     */
    public function publishMonthlyKeyScheduled(): void
    {
        $this->sendMonthlyKeyToGroup();

        // Можно добавить дополнительные уведомления или логику здесь
        Log::info('Telegram bot: Ежемесячный ключ автоматически опубликован в группу.');
    }

    // ====================== БУДУЩАЯ АРХИТЕКТУРА ======================
    /*
     * План развития бота:
     *
     * 1. Command Router (будет позже)
     *    - Вынести обработку команд в отдельный класс TelegramCommandRouter
     *    - Каждый модуль регистрирует свои команды
     *
     * 2. Модульная система (Training Module)
     *    - TrainingModuleInterface
     *    - TopicsRepository (или связь с существующими моделями Document / Quiz)
     *    - ContentDeliveryService (отдача видео/аудио/текста)
     *
     * 3. Хранение контента
     *    - Связь с таблицей documents (уже есть MinIO)
     *    - Новые таблицы: training_topics, training_materials, user_progress
     *
     * 4. Интерактив
     *    - Inline-кнопки (reply_markup)
     *    - Callback query обработка
     *    - Состояния пользователя (FSM) для пошагового обучения
     *
     * 5. Расписание и уведомления
     *    - Ежемесячная публикация ключа (уже реализовано через команду)
     *    - Напоминания о необходимости пройти обучение
     *
     * Пример будущей команды:
     * /topic светофоры
     * /training модуль-1
     * /progress
     */
}
