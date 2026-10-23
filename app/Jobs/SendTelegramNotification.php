<?php

namespace App\Jobs;

use App\Services\TelegramBotApi;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendTelegramNotification implements ShouldQueue, ShouldBeEncrypted
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 45;

    public function __construct(public string $message, public string $chatId, public string $parseMode)
    {
        $this->onConnection(config('services.telegram.notification_queue_connection', 'database'));
        $this->onQueue('notifications');
        $this->afterCommit();
    }

    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(TelegramBotApi $api): void
    {
        $api->message('notifications', $this->chatId, $this->message, $this->parseMode);
    }
}
