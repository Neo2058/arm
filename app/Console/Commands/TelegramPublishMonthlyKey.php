<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class TelegramPublishMonthlyKey extends Command
{
    protected $signature = 'telegram:publish-key {--now}';
    protected $description = 'Устаревшая групповая публикация ключа (отключена)';

    public function handle(): int
    {
        $this->warn('Групповая публикация отключена. Активные привязанные пользователи запрашивают /key в личном чате бота.');

        return self::SUCCESS;
    }
}
