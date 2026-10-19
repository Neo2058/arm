<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\ClickHouseService;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;

class LogAuthenticationEvents
{
    public function login(Login $event): void
    {
        $user = $event->user instanceof User ? $event->user : null;
        $via = self::via();

        if ($user && ! $user->is_active) {
            ClickHouseService::log('login_failed', $user->id, [
                'reason' => 'inactive',
                'guard' => $event->guard,
                'via' => $via,
                'ip' => request()->ip(),
            ], $user);

            return;
        }

        $action = $via === 'mobile' ? 'mobile.login' : 'login';
        ClickHouseService::log($action, $user?->id ?? 0, [
            'guard' => $event->guard,
            'via' => $via,
        ], $user);
    }

    public function failed(Failed $event): void
    {
        $email = is_array($event->credentials) ? ($event->credentials['email'] ?? null) : null;
        $email = is_string($email) ? mb_substr($email, 0, 200) : null;
        $reason = 'credentials';

        if (is_string($email) && $email !== '') {
            $existing = User::query()->where('email', $email)->first();
            if ($existing && ! $existing->is_active) {
                $reason = 'inactive';
            }
        }

        ClickHouseService::log('login_failed', 0, [
            'reason' => $reason,
            'email' => $email,
            'guard' => $event->guard,
            'via' => self::via(),
            'ip' => request()->ip(),
        ]);
    }

    public function logout(Logout $event): void
    {
        $user = $event->user instanceof User ? $event->user : null;
        if ($user && ! $user->is_active) {
            return;
        }

        $via = self::via();
        $action = $via === 'mobile' ? 'mobile.logout' : 'logout';

        ClickHouseService::log($action, $user?->id ?? 0, [
            'guard' => $event->guard,
            'via' => $via,
        ], $user);
    }

    public static function via(): string
    {
        $path = ltrim(request()->path(), '/');
        if (str_starts_with($path, 'api/mobile')) {
            return 'mobile';
        }
        if (str_starts_with($path, 'admin')) {
            return 'filament';
        }

        return 'web';
    }
}
