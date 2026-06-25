<?php

namespace App\Services;

use App\Mail\NewAdminNotification;
use App\Models\AdminNotification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class AdminNotificationService
{
    /**
     * Создаёт уведомление в админке и отправляет email.
     */
    public static function notify(
        string $type,
        string $title,
        string $message,
        array $data = []
    ): void {
        try {
            $notification = AdminNotification::create([
                'type' => $type,
                'title' => $title,
                'message' => $message,
                'data' => $data,
            ]);

            // Отправляем email
            Mail::to('bobrov4709@mail.ru')->send(new NewAdminNotification($notification));

            // Пересылка в Telegram через Web3Forms (обход прямого API)
            if (env('WEB3FORMS_ACCESS_KEY')) {
                try {
                    Http::asForm()->timeout(10)->post('https://api.web3forms.com/submit', [
                        'access_key' => env('WEB3FORMS_ACCESS_KEY'),
                        'subject' => $title,
                        'message' => $message,
                        'from_name' => config('app.name'),
                        'replyto' => config('mail.from.address'),
                    ]);
                } catch (\Throwable $e) {
                    Log::warning('Web3Forms relay failed', ['error' => $e->getMessage()]);
                }
            }

        } catch (\Throwable $e) {
            Log::error('AdminNotificationService failed', [
                'error' => $e->getMessage(),
                'type' => $type,
                'title' => $title,
            ]);
        }
    }
}
