<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Yookassa (ЮKassa)
    |--------------------------------------------------------------------------
    | Для донатов в Backstage.
    | Добавьте в .env:
    | YOOKASSA_SHOP_ID=...
    | YOOKASSA_SECRET_KEY=...
    |
    | Webhook URL: https://your-domain/webhooks/yookassa
    | В личном кабинете ЮKassa укажите этот URL и включите уведомления о статусе платежа.
    */
    'yookassa' => [
        'shop_id' => env('YOOKASSA_SHOP_ID'),
        'secret_key' => env('YOOKASSA_SECRET_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'telegram' => [
        'bot_token' => env('TELEGRAM_NOTIFICATIONS_BOT_TOKEN') ?: env('TELEGRAM_BOT_TOKEN'),
        'chat_id' => env('TELEGRAM_NOTIFICATIONS_CHAT_ID') ?: env('TELEGRAM_CHAT_ID'),
        'key_bot_token' => env('TELEGRAM_KEY_BOT_TOKEN'),
        'key_bot_username' => env('TELEGRAM_KEY_BOT_USERNAME'),
        'key_webhook_secret' => env('TELEGRAM_KEY_WEBHOOK_SECRET'),
        'key_relay_url' => env('TELEGRAM_KEY_RELAY_URL'),
        'key_relay_secret' => env('TELEGRAM_KEY_RELAY_SECRET'),
        'notification_queue_connection' => env('TELEGRAM_NOTIFICATIONS_QUEUE_CONNECTION', 'database'),
        'group_id'   => env('TELEGRAM_GROUP_ID'),    // ID закрытой группы
        'bot_username' => env('TELEGRAM_BOT_USERNAME'),
        'api_url' => env('TELEGRAM_API_URL', 'https://api.telegram.org'),
        // Relay for bypassing direct Telegram API calls (e.g. self-hosted proxy on another server)
        'relay_url' => env('TELEGRAM_RELAY_URL'),
        'relay_secret' => env('TELEGRAM_RELAY_SECRET'),
    ],

];
