<?php

return [
    'enabled' => env('CHAT_ENABLED', false), // mobile API + /me.features.chat + tab only; Filament ignores
    'pilot_column' => env('CHAT_PILOT_COLUMN'),
    'key' => env('CHAT_ENCRYPTION_KEY'), // base64:<32 bytes> like APP_KEY
    'previous_keys' => array_filter(explode(',', (string) env('CHAT_PREVIOUS_KEYS', ''))),
    'cipher' => 'aes-256-gcm',
    'poll_seconds' => 15,
    'message_max' => 4000,
    'attachment_max_bytes' => 10 * 1024 * 1024,
    'timezone' => env('CHAT_TIMEZONE', 'Europe/Moscow'),
];
