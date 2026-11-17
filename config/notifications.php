<?php

return [
    'enabled' => filter_var(env('NOTIFICATIONS_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
    'poll_seconds' => (int) env('NOTIFICATIONS_POLL_SECONDS', 30),
    'vapid' => [
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        'subject' => env('VAPID_SUBJECT', 'mailto:noreply@localhost'),
    ],
];
