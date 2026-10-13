<?php

namespace App\Services;

use App\Models\User;
use ClickHouseDB\Client;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class ClickHouseService
{
    public const SYSTEM_ROLE = 'system';

    /**
     * @var list<string>
     */
    private const COLUMNS = [
        'event_date', 'event_time', 'user_id', 'user_role',
        'user_column', 'action_type', 'resource_id', 'details',
    ];

    /**
     * Analytics event. Queued on `analytics`.
     *
     * $actor — User, user id, or null (Auth::user(), otherwise system: user_id=0).
     * Do not call this after ActionLog::log() for the same event: ActionLog already forwards here.
     */
    public static function log(string $actionType, int $resourceId = 0, mixed $details = '', User|int|null $actor = null): void
    {
        $data = self::payload($actionType, $resourceId, $details, $actor);

        dispatch(function () use ($data) {
            try {
                self::insert($data);
            } catch (\Throwable $e) {
                Log::warning('Failed to insert user_actions into ClickHouse', [
                    'action' => $data['action_type'] ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
        })->onQueue('analytics');
    }

    /**
     * @return array{
     *     event_date: string,
     *     event_time: string,
     *     user_id: int,
     *     user_role: string,
     *     user_column: string,
     *     action_type: string,
     *     resource_id: int,
     *     details: string
     * }
     */
    public static function payload(string $actionType, int $resourceId = 0, mixed $details = '', User|int|null $actor = null): array
    {
        $user = self::resolveActor($actor);

        return [
            'event_date' => date('Y-m-d'),
            'event_time' => date('Y-m-d H:i:s'),
            'user_id' => $user?->id ?? 0,
            'user_role' => $user ? $user->roleValue() : self::SYSTEM_ROLE,
            'user_column' => $user ? ($user->profile?->column ?? 'Не указана') : '',
            'action_type' => $actionType,
            'resource_id' => $resourceId,
            'details' => is_array($details) ? json_encode($details, JSON_UNESCAPED_UNICODE) : (string) $details,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function insert(array $data): void
    {
        $client = new Client(config('clickhouse'));
        $client->insert('user_actions', [$data], self::COLUMNS);
    }

    private static function resolveActor(User|int|null $actor): ?User
    {
        if ($actor instanceof User) {
            $actor->loadMissing('profile');

            return $actor;
        }

        if (is_int($actor) && $actor > 0) {
            return User::query()->with('profile')->find($actor);
        }

        $user = Auth::user();
        if ($user instanceof User) {
            $user->loadMissing('profile');

            return $user;
        }

        return null;
    }
}
