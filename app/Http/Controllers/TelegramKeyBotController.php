<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\TelegramService;
use App\Services\ClickHouseService;

class TelegramKeyBotController extends Controller
{
    private function getDailySequence(): array
    {
        $day   = (int)date('d');
        $month = (int)date('m');
        $year  = (int)date('Y');

        $salt = config('app.barrier_salt', 'MySecretBarrier2026');

        $baseString = "{$year}-{$month}-{$day}-{$salt}";
        $hash = hash('sha256', $baseString);

        return [
            hexdec(substr($hash, 0, 2)) % 4,
            hexdec(substr($hash, 2, 2)) % 4,
            hexdec(substr($hash, 4, 2)) % 4,
            hexdec(substr($hash, 6, 2)) % 4,
        ];
    }

    public function webhook(Request $request)
    {
        $update = $request->all();

        if (!isset($update['message'])) {
            return response()->json(['ok' => true]);
        }

        $message = $update['message'];
        $chatId  = $message['chat']['id'];
        $text    = trim($message['text'] ?? '');

        // Проверяем, что это наша группа
        if ((string)$chatId !== (string)config('services.telegram.group_id')) {
            return response()->json(['ok' => true]);
        }

        // Поддержка /key и /key@BotName
        if (str_starts_with(strtolower($text), '/key')) {

            $sequence = $this->getDailySequence();
            $monthName = date('F Y'); // Пример: "May 2026"

            $responseText = "🔑 <b>Ключ на этот месяц ({$monthName})</b>\n\n";
            $responseText .= "Комбинация: <code>" . implode(' ', $sequence) . "</code>\n\n";
            $responseText .= "✅ Эта комбинация действует до конца месяца.";

            TelegramService::send($responseText, $chatId, 'HTML');

            ClickHouseService::log('telegram_key_requested', 0, ['chat_id' => $chatId]);
        }

        return response()->json(['ok' => true]);
    }
}
