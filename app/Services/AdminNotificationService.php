<?php

namespace App\Services;

use App\Mail\NewAdminNotification;
use App\Models\AdminNotification;
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

        } catch (\Throwable $e) {
            Log::error('AdminNotificationService failed', [
                'error' => $e->getMessage(),
                'type' => $type,
                'title' => $title,
            ]);
        }
    }
}
