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
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'chat_id' => env('TELEGRAM_CHAT_ID'),
        'group_id'   => env('TELEGRAM_GROUP_ID'),    // ID закрытой группы
        'bot_username' => env('TELEGRAM_BOT_USERNAME'),
        'api_url' => env('TELEGRAM_API_URL', 'https://api.telegram.org'),  // Можно заменить на прокси третьей стороны
    ],

];
