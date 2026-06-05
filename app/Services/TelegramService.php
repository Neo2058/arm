<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class TelegramService
{
    /**
     * Универсальный метод отправки сообщения
     */
    public static function send(
        string $message,
        ?string $chatId = null,
        string $parseMode = 'Markdown'
    ): void {
        $botToken = config('services.telegram.bot_token');
        $defaultChatId = config('services.telegram.chat_id');

        $chatId = $chatId ?? $defaultChatId;

        if (empty($botToken) || empty($chatId)) {
            Log::warning('Telegram send failed: empty token or chat_id');
            return;
        }

        try {
            $response = Http::timeout(10)
                ->post("https://api.telegram.org/bot{$botToken}/sendMessage", [
                    'chat_id'    => $chatId,
                    'text'       => $message,
                    'parse_mode' => $parseMode,
                ]);

            if ($response->failed()) {
                Log::error('Telegram API error', [
                    'status' => $response->status(),
                    'body'   => $response->json(),
                    'chat_id'=> $chatId
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('Telegram send failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Отправка фото (оставляем как было, только улучшили)
     */
    public static function sendPhoto(string $message, string $filePathInMinio): void
    {
        $botToken = config('services.telegram.bot_token');
        $chatId = config('services.telegram.chat_id');

        if (empty($botToken) || empty($chatId)) {
            return;
        }

        try {
            $fileStream = Storage::disk('s3')->readStream($filePathInMinio);
            if ($fileStream === false) {
                Log::warning('Telegram photo stream failed', ['path' => $filePathInMinio]);
                return;
            }

            $response = Http::timeout(30)
                ->attach('photo', $fileStream, basename($filePathInMinio))
                ->post("https://api.telegram.org/bot{$botToken}/sendPhoto", [
                    'chat_id'    => $chatId,
                    'caption'    => $message,
                    'parse_mode' => 'Markdown',
                ]);

            if ($response->failed()) {
                Log::error('Telegram Photo API error', [
                    'status' => $response->status(),
                    'body'   => $response->json(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('Telegram photo send failed', [
                'error' => $e->getMessage(),
                'path'  => $filePathInMinio,
            ]);
        }
    }
}
