<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TelegramAccountLinker
{
    public function issue(User $user): string
    {
        return DB::transaction(function () use ($user) {
            $current = User::query()->lockForUpdate()->findOrFail($user->id);
            abort_unless($current->is_active, 403);
            abort_if(DB::table('telegram_accounts')->where('user_id', $user->id)->exists(), 409, 'Сначала отвяжите текущий Telegram.');
            $token = Str::random(48);
            DB::table('telegram_link_tokens')->updateOrInsert(['user_id' => $user->id], [
                'token_hash' => hash('sha256', $token), 'expires_at' => now()->addMinutes(10),
            ]);

            return $token;
        });
    }

    public function consume(string $token, int $telegramId): ?User
    {
        if (! preg_match('/^[A-Za-z0-9]{48}$/D', $token)) {
            return null;
        }

        return DB::transaction(function () use ($token, $telegramId) {
            // Lock user first, consistently with issue/unlink. Unique constraints also protect cross-user races.
            $pending = DB::table('telegram_link_tokens')->where('token_hash', hash('sha256', $token))->first();
            if (! $pending) {
                return null;
            }
            $user = User::query()->lockForUpdate()->find($pending->user_id);
            $pending = DB::table('telegram_link_tokens')->where('user_id', $pending->user_id)
                ->where('token_hash', hash('sha256', $token))->where('expires_at', '>', now())->first();
            if (! $pending || ! $user?->is_active
                || DB::table('telegram_accounts')->where('user_id', $user->id)->exists()
                || DB::table('telegram_accounts')->where('telegram_user_id', $telegramId)->exists()) {
                return null;
            }
            DB::table('telegram_accounts')->insert([
                'user_id' => $user->id, 'telegram_user_id' => $telegramId, 'linked_at' => now(),
            ]);
            DB::table('telegram_link_tokens')->where('user_id', $user->id)->delete();
            ClickHouseService::log('telegram.account_linked', $user->id, ['telegram_user_id' => $telegramId], $user);

            return $user;
        });
    }

    public function user(int $telegramId): ?User
    {
        $id = DB::table('telegram_accounts')->where('telegram_user_id', $telegramId)->value('user_id');

        return $id ? User::query()->where('is_active', true)->find($id) : null;
    }

    public function unlink(User $user): void
    {
        DB::transaction(function () use ($user) {
            User::query()->lockForUpdate()->findOrFail($user->id);
            DB::table('telegram_accounts')->where('user_id', $user->id)->delete();
            DB::table('telegram_link_tokens')->where('user_id', $user->id)->delete();
        });
        ClickHouseService::log('telegram.account_unlinked', $user->id, [], $user);
    }
}
