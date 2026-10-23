<?php

namespace App\Services;

use App\Jobs\SendTelegramNotification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class TelegramService
{
    // Existing callers are administrative alerts; the key bot has a separate transport identity.
    public static function enabled(): bool
    {
        return app(TelegramBotApi::class)->configured('notifications');
    }

    public static function send(string $message, ?string $chatId = null, string $parseMode = 'Markdown'): void
    {
        $chatId ??= config('services.telegram.chat_id') ?: config('services.telegram.group_id');
        if (! self::enabled() || ! filled($chatId)) {
            return;
        }
        try {
            SendTelegramNotification::dispatch($message, (string) $chatId, $parseMode);
        } catch (\Throwable) {
            // Covers sync driver and queue backend failures without breaking login/registration.
            Log::warning('Telegram notification could not be dispatched');
        }
    }

    public static function sendPhoto(string $filePathInMinio, ?int $chatId = null, ?string $caption = null, string $parseMode = 'HTML', ?string $filename = null): void
    {
        self::sendFile('sendPhoto', 'photo', $filePathInMinio, $chatId, $caption, $parseMode, $filename);
    }

    public static function sendVideo(string $filePathInMinio, ?int $chatId = null, ?string $caption = null, string $parseMode = 'HTML', ?string $filename = null): void
    {
        self::sendFile('sendVideo', 'video', $filePathInMinio, $chatId, $caption, $parseMode, $filename);
    }

    public static function sendAudio(string $filePathInMinio, ?int $chatId = null, ?string $caption = null, string $parseMode = 'HTML', ?string $filename = null): void
    {
        self::sendFile('sendAudio', 'audio', $filePathInMinio, $chatId, $caption, $parseMode, $filename);
    }

    public static function sendDocument(string $filePathInMinio, ?int $chatId = null, ?string $caption = null, string $parseMode = 'HTML', ?string $filename = null): void
    {
        self::sendFile('sendDocument', 'document', $filePathInMinio, $chatId, $caption, $parseMode, $filename);
    }

    private static function sendFile(string $method, string $field, string $path, ?int $chatId, ?string $caption, string $parseMode, ?string $filename): void
    {
        $chatId ??= config('services.telegram.chat_id') ?: config('services.telegram.group_id');
        if (! self::enabled() || ! filled($chatId)) {
            return;
        }
        $stream = null;
        try {
            $relay = config('services.telegram.relay_url');
            if ($relay && ! str_starts_with($relay, 'https://')) {
                throw new \RuntimeException('HTTPS required.');
            }
            $stream = Storage::disk('s3')->readStream($path);
            if (! is_resource($stream)) {
                throw new \RuntimeException('File unavailable.');
            }
            $payload = ['chat_id' => (string) $chatId, 'caption' => $caption ?? '', 'parse_mode' => $parseMode];
            if ($relay) {
                $payload += ['method' => $method, 'secret' => config('services.telegram.relay_secret')];
                $url = $relay;
                $field = 'file';
            } else {
                $url = rtrim(config('services.telegram.api_url'), '/').'/bot'.config('services.telegram.bot_token').'/'.$method;
            }
            $response = Http::withoutRedirecting()->timeout(60)->attach($field, $stream, $filename ?: basename($path))->post($url, $payload);
            if (! $response->successful() || $response->json('ok') !== true) {
                throw new \RuntimeException('Telegram file rejected.');
            }
        } catch (\Throwable) {
            Log::warning('Telegram media delivery failed', ['method' => $method]);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }
}
