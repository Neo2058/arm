<?php

namespace App\Console\Commands;

use App\Http\Controllers\TelegramKeyBotController;
use Illuminate\Console\Command;

class TelegramPublishMonthlyKey extends Command
{
    protected $signature = 'telegram:publish-key {--now : Опубликовать ключ немедленно}';

    protected $description = 'Публикует ежемесячный ключ доступа в Telegram-группу';

    public function handle(): int
    {
        $controller = app(TelegramKeyBotController::class);

        if ($this->option('now')) {
            $this->info('Публикация ключа сейчас...');
            $controller->sendMonthlyKeyToGroup();
            $this->info('Ключ отправлен в группу.');
            return self::SUCCESS;
        }

        $this->info('Публикация ключа по расписанию...');
        $controller->publishMonthlyKey();
        $this->info('Команда выполнена.');

        return self::SUCCESS;
    }
}
