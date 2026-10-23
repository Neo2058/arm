<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class TelegramBotApi
{
    public function configured(string $bot): bool
    {
        return filled($this->setting($bot, 'bot_token'))
            || (filled($this->setting($bot, 'relay_url')) && filled($this->setting($bot, 'relay_secret')));
    }

    public function setting(string $bot, string $key): mixed
    {
        if (! in_array($bot, ['key', 'notifications'], true)) {
            throw new RuntimeException('Unknown Telegram bot.');
        }

        return config('services.telegram.'.($bot === 'key' ? 'key_' : '').$key);
    }

    public function call(string $bot, string $method, array $payload = []): array
    {
        if (! $this->configured($bot)) {
            throw new RuntimeException('Telegram bot is not configured.');
        }
        $relay = $this->setting($bot, 'relay_url');
        if ($relay && ! $this->relayUrlAllowed((string) $relay)) {
            throw new RuntimeException('Telegram relay requires HTTPS or a private HTTP URL.');
        }
        $formatError = false;
        try {
            if ($relay) {
                $response = Http::withoutRedirecting()->timeout(15)->post($relay, [
                    ...$payload, 'method' => $method, 'secret' => $this->setting($bot, 'relay_secret'),
                ]);
            } else {
                $base = rtrim(config('services.telegram.api_url', 'https://api.telegram.org'), '/');
                $response = Http::withoutRedirecting()->timeout(10)->post($base.'/bot'.$this->setting($bot, 'bot_token').'/'.$method, $payload);
            }
            $data = $response->json();
            if ($response->successful() && is_array($data) && ($data['ok'] ?? false) === true) {
                return $data;
            }
            $description = is_array($data) ? ($data['description'] ?? '') : '';
            $formatError = $response->status() === 400 && is_string($description)
                && (str_contains($description, 'parse') || str_contains($description, 'entities'));
            // Never log payloads, bot URLs, credentials or API error bodies.
            Log::warning('Telegram API request failed', ['bot' => $bot, 'method' => $method, 'status' => $response->status()]);
        } catch (Throwable) {
            Log::warning('Telegram transport unavailable', ['bot' => $bot, 'method' => $method]);
        }

        throw new RuntimeException('Telegram request failed; check configuration and network.', $formatError ? 400 : 0);
    }

    public function relayUrlAllowed(string $relay): bool
    {
        if (str_starts_with($relay, 'https://')) {
            return true;
        }
        // Private HTTP is for a loopback/SSH tunnel to a relay on an unblocked machine.
        return (bool) preg_match(
            '#^http://(127\.0\.0\.1|localhost|10\.\d+\.\d+\.\d+|192\.168\.\d+\.\d+|172\.(1[6-9]|2\d|3[0-1])\.\d+\.\d+)(:\d+)?/#',
            $relay
        );
    }

    public function message(string $bot, string $chatId, string $text, string $parseMode = '', bool $protect = false): void
    {
        $payload = ['chat_id' => $chatId, 'text' => mb_substr($text, 0, 4000), 'protect_content' => $protect];
        if ($parseMode !== '') {
            $payload['parse_mode'] = $parseMode;
        }
        try {
            $this->call($bot, 'sendMessage', $payload);
        } catch (RuntimeException $e) {
            if ($parseMode === '' || $e->getCode() !== 400) {
                throw $e;
            }
            // Existing alerts contain user-supplied names: malformed Markdown must not lose the alert.
            unset($payload['parse_mode']);
            $this->call($bot, 'sendMessage', $payload);
        }
    }
}
