<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class TelegramPasswordReset
{
    public function issue(int $telegramId): ?string
    {
        $actor = null;
        $token = DB::transaction(function () use ($telegramId, &$actor) {
            $account = DB::table('telegram_accounts')->where('telegram_user_id', $telegramId)->first();
            if (! $account) {
                return null;
            }
            $user = User::query()->lockForUpdate()->find($account->user_id);
            $account = DB::table('telegram_accounts')->where('id', $account->id)->first();
            if (! $user?->is_active || ! $account) {
                return null;
            }
            $pending = DB::table('telegram_password_resets')->where('user_id', $user->id)->first();
            if ($pending && now()->diffInSeconds($pending->issued_at, true) < 60) {
                return null;
            }
            $token = Str::random(64);
            DB::table('telegram_password_resets')->updateOrInsert(['user_id' => $user->id], [
                'telegram_account_id' => $account->id, 'token_hash' => hash('sha256', $token),
                'issued_at' => now(), 'expires_at' => now()->addMinutes(10),
            ]);
            $actor = $user;

            return $token;
        });
        if ($token && $actor) {
            $this->audit('password_reset_requested', $actor);
        }

        return $token;
    }

    public function reset(string $token, string $password): ?User
    {
        if (! preg_match('/^[A-Za-z0-9]{64}$/D', $token)) {
            return null;
        }

        $user = DB::transaction(function () use ($token, $password) {
            $hash = hash('sha256', $token);
            $pending = DB::table('telegram_password_resets')->where('token_hash', $hash)->first();
            if (! $pending) {
                return null;
            }
            // Same lock order as issue/link/unlink. Re-read after acquiring the lock.
            $user = User::query()->lockForUpdate()->find($pending->user_id);
            $pending = DB::table('telegram_password_resets')->where('user_id', $pending->user_id)
                ->where('token_hash', $hash)->where('expires_at', '>', now())->first();
            if (! $user?->is_active || ! $pending
                || ! DB::table('telegram_accounts')->where('id', $pending->telegram_account_id)
                    ->where('user_id', $user->id)->exists()) {
                return null;
            }
            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
            $user->tokens()->delete();
            DB::table('telegram_password_resets')->where('user_id', $user->id)->delete();
            if (config('session.driver') === 'database') {
                DB::connection(config('session.connection'))->table(config('session.table', 'sessions'))
                    ->where('user_id', $user->id)->delete();
            }

            return $user;
        });
        if ($user) {
            $this->audit('password_reset_completed', $user);
        }

        return $user;
    }

    private function audit(string $action, User $user): void
    {
        try {
            ClickHouseService::log($action, $user->id, ['channel' => 'telegram'], $user);
        } catch (\Throwable) {
            // An unavailable analytics queue must not turn a completed reset into an apparent failure.
            Log::warning('Password recovery audit could not be queued', ['action' => $action, 'user_id' => $user->id]);
        }
    }

    public function url(string $token): string
    {
        // Never use the inbound Host header to build a recovery link.
        $base = rtrim((string) config('app.url'), '/');
        $base = preg_replace('#^https://(?:www\.)?(?:xn---15-7edz\.xn--p1ai|тч-15\.рф)(?=/|$)#i', 'https://www.xn---15-7edz.xn--p1ai', $base);
        if (! str_starts_with($base, 'https://')) {
            throw new \RuntimeException('Password recovery requires an HTTPS APP_URL.');
        }

        return $base.'/password/reset#'.$token;
    }
}
