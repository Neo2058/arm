<?php

namespace App\Http\Controllers;

use App\Services\ClickHouseService;
use App\Services\MonthlyAccessKey;
use App\Services\TelegramAccountLinker;
use App\Services\TelegramBotApi;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;

class TelegramKeyBotController extends Controller
{
    public function webhook(Request $request, TelegramBotApi $api, TelegramAccountLinker $links, MonthlyAccessKey $keys)
    {
        $secret = config('services.telegram.key_webhook_secret');
        abort_unless(is_string($secret) && preg_match('/^[A-Za-z0-9_-]{32,256}$/D', $secret)
            && hash_equals($secret, (string) $request->header('X-Telegram-Bot-Api-Secret-Token')), 403);
        abort_unless($api->configured('key'), 503);

        $data = $request->validate([
            'update_id' => 'required|integer|min:0',
            'message' => 'sometimes|array',
            'message.chat.id' => 'required_with:message|integer',
            'message.chat.type' => 'required_with:message|string',
            'message.from.id' => 'required_with:message|integer|min:1',
            'message.from.is_bot' => 'sometimes|boolean',
            'message.text' => 'sometimes|string|max:4096',
        ]);
        $message = $data['message'] ?? null;
        // No group messages, forwarded messages or bot identities may receive a key or consume a link.
        if (! $message || $message['chat']['type'] !== 'private'
            || (string) $message['chat']['id'] !== (string) $message['from']['id']
            || ($message['from']['is_bot'] ?? false) || $request->input('message.forward_origin')) {
            return response()->json(['ok' => true]);
        }
        $id = (int) $message['from']['id'];
        $done = 'telegram-key-update:'.$data['update_id'];
        $lock = Cache::lock($done.':lock', 45);
        abort_unless($lock->get(), 503);
        try {
            if (Cache::has($done)) {
                return response()->json(['ok' => true]);
            }
            // A genuine Telegram sender is known only after secret validation.
            $limit = 'telegram-key-sender:'.$id;
            if (RateLimiter::tooManyAttempts($limit, 10)) {
                return response()->json(['ok' => true]);
            }
            RateLimiter::hit($limit, 60);
            $text = trim($message['text'] ?? '');
            preg_match('/^\/(start|key|help)(?:@[A-Za-z0-9_]+)?(?:\s+(.*))?$/sD', $text, $command);
            $name = $command[1] ?? '';
            $argument = $command[2] ?? '';
            $user = $links->user($id);
            if ($name === 'start' && $argument !== '') {
                $user = $links->consume($argument, $id);
                $reply = $user ? 'Telegram привязан к вашей учётной записи ARM. Отправьте /key для получения ключа.'
                    : 'Ссылка недействительна или уже использована. Откройте страницу Telegram в ARM. Если аккаунт уже привязан, отправьте /key.';
            } elseif ($name === 'key' && $user) {
                $reply = 'Ключ доступа на '.date('m.Y').': '.implode(' ', $keys->sequence())."\nДействует до конца месяца. Не передавайте ключ другим.";
                // Do not put the key or link token into analytics.
            } else {
                $reply = $user ? 'Команда /key — ключ на текущий месяц. Отвязать Telegram можно в ARM.'
                    : 'Доступ только для зарегистрированных активных пользователей ARM. Войдите на сайт и привяжите Telegram: '.route('telegram.link.show');
            }
            $api->message('key', (string) $id, $reply, '', true);
            if ($name === 'key' && $user) {
                ClickHouseService::log('telegram_key_requested', 0, ['telegram_user_id' => $id, 'month' => date('Y-m')], $user);
            }
            Cache::put($done, true, now()->addDay());
        } catch (RuntimeException) {
            // Telegram will retry. No exception URL or request payload is exposed.
            return response()->json(['ok' => false], 503);
        } finally {
            $lock->release();
        }

        return response()->json(['ok' => true]);
    }
}
