<?php

namespace App\Services;

use ClickHouseDB\Client;
use Illuminate\Support\Facades\Auth;

class ClickHouseService
{
    public static function log($actionType, $resourceId = 0, $details = '')
    {
        $user = Auth::user();
        if (! $user) {
            return;
        }

        // Подгружаем профиль, чтобы знать номер колонны (безопасно, профиль может отсутствовать)
        $profile = $user->profile;
        $userColumn = $profile?->column ?? 'Не указана';

        $userRole = $user->roleValue();

        $data = [
            'event_date' => date('Y-m-d'),
            'event_time' => date('Y-m-d H:i:s'),
            'user_id' => $user->id,
            'user_role' => $userRole,
            'user_column' => $userColumn,
            'action_type' => $actionType,
            'resource_id' => $resourceId,
            'details' => is_array($details) ? json_encode($details, JSON_UNESCAPED_UNICODE) : $details,
        ];

        // Отправляем в фоновую очередь Redis
        dispatch(function () use ($data) {
            $client = new Client(config('clickhouse'));
            $client->insert('user_actions', [$data], [
                'event_date', 'event_time', 'user_id', 'user_role',
                'user_column', 'action_type', 'resource_id', 'details',
            ]);
        })->onQueue('analytics');
    }
}
