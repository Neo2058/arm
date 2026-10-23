<?php

namespace App\Console\Commands;

use App\Services\TelegramBotApi;
use Illuminate\Console\Command;
use RuntimeException;

class TelegramManage extends Command
{
    protected $signature = 'telegram:manage {bot=key : key или notifications} {--webhook : Установить webhook бота ключей} {--updates : Показать только ID чатов из ожидающих сообщений} {--clear-webhook : Удалить webhook выбранного бота} {--test : Отправить тест в настроенный административный чат}';
    protected $description = 'Проверка двух ботов и настройка webhook без вывода токенов';

    public function handle(TelegramBotApi $api): int
    {
        $bot = $this->argument('bot');
        if (! in_array($bot, ['key', 'notifications'], true)) {
            $this->error('Выберите key или notifications.');

            return self::FAILURE;
        }
        try {
            $keyToken = $api->setting('key', 'bot_token');
            if (filled($keyToken) && $keyToken === $api->setting('notifications', 'bot_token')) {
                throw new RuntimeException('Бот ключей и бот уведомлений должны иметь разные токены.');
            }
            $me = $api->call($bot, 'getMe')['result'];
            $this->info('Бот: @'.($me['username'] ?? '').' (ID '.($me['id'] ?? '').')');
            if ($bot === 'key' && strcasecmp($me['username'] ?? '', config('services.telegram.key_bot_username') ?? '') !== 0) {
                throw new RuntimeException('TELEGRAM_KEY_BOT_USERNAME не совпадает с getMe.');
            }
            if ($this->option('clear-webhook')) {
                $api->call($bot, 'deleteWebhook', ['drop_pending_updates' => false]);
                $this->info('Webhook удалён, ожидающие сообщения сохранены.');
            }
            if ($this->option('webhook')) {
                if ($bot !== 'key') {
                    throw new RuntimeException('Webhook нужен только боту key.');
                }
                $secret = config('services.telegram.key_webhook_secret');
                $url = route('telegram.key.webhook');
                if (! is_string($secret) || ! preg_match('/^[A-Za-z0-9_-]{32,256}$/D', $secret) || ! str_starts_with($url, 'https://')) {
                    throw new RuntimeException('Нужны HTTPS APP_URL и webhook secret из 32–256 символов A-Z a-z 0-9 _ -.');
                }
                $api->call($bot, 'setWebhook', ['url' => $url, 'secret_token' => $secret, 'allowed_updates' => ['message']]);
                $this->info('Webhook установлен: '.$url);
            }
            $info = $api->call($bot, 'getWebhookInfo')['result'];
            $this->line('Webhook: '.(($info['url'] ?? '') !== '' ? 'установлен' : 'не установлен'));
            $this->line('Ожидающих updates: '.($info['pending_update_count'] ?? 0));
            if (isset($info['last_error_date'])) {
                $this->warn('Telegram сообщает об ошибке доставки webhook; проверьте HTTPS и secret.');
            }
            if ($this->option('updates')) {
                if (! empty($info['url'])) {
                    throw new RuntimeException('Для getUpdates сначала удалите webhook (--clear-webhook).');
                }
                $updates = $api->call($bot, 'getUpdates', ['timeout' => 0, 'allowed_updates' => ['message']])['result'];
                foreach ($updates as $update) {
                    $chat = $update['message']['chat'] ?? null;
                    if ($chat) {
                        $this->line('chat_id='.$chat['id'].' type='.$chat['type']);
                    }
                }
                if (! $updates) {
                    $this->info('Отправьте боту /start (или команду в группе) и повторите --updates.');
                }
            }
            if ($this->option('test')) {
                if ($bot !== 'notifications' || ! filled($chat = config('services.telegram.chat_id') ?: config('services.telegram.group_id'))) {
                    throw new RuntimeException('Для --test нужны notifications и TELEGRAM_NOTIFICATIONS_CHAT_ID.');
                }
                $api->message($bot, (string) $chat, 'ARM: тест административных уведомлений. '.now()->format('d.m.Y H:i:s'));
                $this->info('Тест доставлен Telegram API.');
            }
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
