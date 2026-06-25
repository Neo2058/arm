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
        $defaultChatId = config('services.telegram.chat_id') ?? config('services.telegram.group_id');

        $chatId = $chatId ?? $defaultChatId;

        if (empty($botToken) || empty($chatId)) {
            Log::warning('Telegram send failed: empty token or chat_id');
            return;
        }

        // Relay mode: forward to another server (in another country) that will call Telegram API
        $relayUrl = config('services.telegram.relay_url');
        $relaySecret = config('services.telegram.relay_secret');

        if (!empty($relayUrl)) {
            try {
                $payload = [
                    'secret'    => $relaySecret,
                    'chat_id'   => $chatId,
                    'message'   => $message,
                    'parse_mode' => $parseMode,
                    'method'    => 'sendMessage',
                ];

                $response = Http::asForm()->timeout(15)->post($relayUrl, $payload);

                if ($response->failed()) {
                    Log::error('Telegram relay error', [
                        'status' => $response->status(),
                        'body'   => $response->body(),
                    ]);
                }
                return;
            } catch (\Throwable $e) {
                Log::error('Telegram relay failed', ['error' => $e->getMessage()]);
                return;
            }
        }

        // Direct mode
        $apiBase = config('services.telegram.api_url', 'https://api.telegram.org');

        try {
            $response = Http::timeout(10)
                ->post("{$apiBase}/bot{$botToken}/sendMessage", [
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
     * Отправка фото
     */
    public static function sendPhoto(
        string $filePathInMinio,
        ?int $chatId = null,
        ?string $caption = null,
        string $parseMode = 'HTML',
        ?string $filename = null
    ): void {
        self::sendFile('sendPhoto', 'photo', $filePathInMinio, $chatId, $caption, $parseMode, $filename);
    }

    /**
     * Отправка видео
     */
    public static function sendVideo(
        string $filePathInMinio,
        ?int $chatId = null,
        ?string $caption = null,
        string $parseMode = 'HTML',
        ?string $filename = null
    ): void {
        self::sendFile('sendVideo', 'video', $filePathInMinio, $chatId, $caption, $parseMode, $filename);
    }

    /**
     * Отправка аудио
     */
    public static function sendAudio(
        string $filePathInMinio,
        ?int $chatId = null,
        ?string $caption = null,
        string $parseMode = 'HTML',
        ?string $filename = null
    ): void {
        self::sendFile('sendAudio', 'audio', $filePathInMinio, $chatId, $caption, $parseMode, $filename);
    }

    /**
     * Отправка документа (файл любого типа)
     */
    public static function sendDocument(
        string $filePathInMinio,
        ?int $chatId = null,
        ?string $caption = null,
        string $parseMode = 'HTML',
        ?string $filename = null
    ): void {
        self::sendFile('sendDocument', 'document', $filePathInMinio, $chatId, $caption, $parseMode, $filename);
    }

    /**
     * Универсальный метод отправки медиафайла
     */
    private static function sendFile(
        string $telegramMethod,   // sendPhoto, sendVideo, sendAudio, sendDocument
        string $fieldName,        // photo, video, audio, document
        string $filePathInMinio,
        ?int $chatId = null,
        ?string $caption = null,
        string $parseMode = 'HTML',
        ?string $filename = null
    ): void {
        $botToken = config('services.telegram.bot_token');
        $defaultChatId = config('services.telegram.chat_id') ?? config('services.telegram.group_id');
        $relayUrl = config('services.telegram.relay_url');
        $relaySecret = config('services.telegram.relay_secret');

        $chatId = $chatId ?? $defaultChatId;

        if (empty($botToken) || empty($chatId)) {
            Log::warning('Telegram sendFile failed: empty token or chat_id');
            return;
        }

        // Relay mode for files
        if (!empty($relayUrl)) {
            try {
                $fileStream = Storage::disk('s3')->readStream($filePathInMinio);
                if ($fileStream === false) {
                    Log::warning('Telegram file stream failed for relay', ['path' => $filePathInMinio]);
                    return;
                }

                $attachFilename = $filename ?: basename($filePathInMinio);

                $payload = [
                    'secret' => $relaySecret,
                    'chat_id' => $chatId,
                    'method' => $telegramMethod,
                    'caption' => $caption,
                    'parse_mode' => $parseMode,
                ];

                $response = Http::asMultipart()->timeout(60)
                    ->attach('file', $fileStream, $attachFilename)
                    ->post($relayUrl, $payload);

                if ($response->failed()) {
                    Log::error('Telegram relay ' . $telegramMethod . ' error', [
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);
                }
                return;
            } catch (\Throwable $e) {
                Log::error('Telegram relay ' . $telegramMethod . ' failed', ['error' => $e->getMessage()]);
                return;
            }
        }

        // Direct
        $apiBase = config('services.telegram.api_url', 'https://api.telegram.org');

        try {
            $fileStream = Storage::disk('s3')->readStream($filePathInMinio);

            if ($fileStream === false) {
                Log::warning('Telegram file stream failed', ['path' => $filePathInMinio]);
                return;
            }

            $attachFilename = $filename ?: basename($filePathInMinio);

            $payload = [
                'chat_id'    => $chatId,
                'parse_mode' => $parseMode,
            ];

            if ($caption) {
                $payload['caption'] = $caption;
            }

            $response = Http::timeout(60)
                ->attach($fieldName, $fileStream, $attachFilename)
                ->post("{$apiBase}/bot{$botToken}/{$telegramMethod}", $payload);

            if ($response->failed()) {
                Log::error('Telegram ' . $telegramMethod . ' API error', [
                    'status' => $response->status(),
                    'body'   => $response->json(),
                    'chat_id'=> $chatId,
                    'path'   => $filePathInMinio,
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('Telegram ' . $telegramMethod . ' send failed', [
                'error' => $e->getMessage(),
                'path'  => $filePathInMinio,
            ]);
        }
    }
}
