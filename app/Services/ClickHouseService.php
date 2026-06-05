<?php
namespace App\Services;

use ClickHouseDB\Client;
use Illuminate\Support\Facades\Auth;

class ClickHouseService
{
    public static function log($actionType, $resourceId = 0, $details = '')
    {
        $user = Auth::user();
        if (!$user) return;

        // Подгружаем профиль, чтобы знать номер колонны
        $profile = $user->profile;

        $data = [
            'event_date' => date('Y-m-d'),
            'event_time' => date('Y-m-d H:i:s'),
            'user_id' => $user->id,
            'user_role' => $user->role,
            'user_column' => $profile->column ?? 'Не указана',
            'action_type' => $actionType,
            'resource_id' => $resourceId,
            'details' => is_array($details) ? json_encode($details, JSON_UNESCAPED_UNICODE) : $details,
        ];

        // Отправляем в фоновую очередь Redis
        dispatch(function () use ($data) {
            $client = new Client(config('clickhouse'));
            $client->insert('user_actions', [$data], [
                'event_date', 'event_time', 'user_id', 'user_role',
                'user_column', 'action_type', 'resource_id', 'details'
            ]);
        })->onQueue('analytics');
    }
}
