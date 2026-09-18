<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAccessRequestRequest;
use App\Models\AccessRequest;
use App\Services\AdminNotificationService;
use App\Services\ClickHouseService;
use App\Services\TelegramService;
use Illuminate\Support\Facades\Log;

class AccessRequestController extends Controller
{
    /**
     * Показать страницу описания сервиса + формы заявки.
     */
    public function show()
    {
        return view('main.about');
    }

    /**
     * Обработка заявки на создание учётной записи.
     * Строгая валидация + защита от атак + уведомление в Telegram.
     */
    public function submit(StoreAccessRequestRequest $request)
    {
        // Honeypot защита от ботов (поле не в правилах, чтобы не раскрывать его 422)
        if ($request->filled('website')) {
            Log::warning('Potential bot detected in access request', [
                'ip' => $request->ip(),
                'ua' => $request->userAgent(),
            ]);

            return back()->with('success', 'Заявка принята. Ожидайте ответа.');
        }

        $tabNumber = $request->validated('tab_number');
        $fio = $request->validated('fio');

        // Сохраняем заявку
        $accessRequest = AccessRequest::create([
            'tab_number' => $tabNumber,
            'fio' => $fio,
            'ip_address' => $request->ip(),
            'user_agent' => substr($request->userAgent() ?? '', 0, 500),
            'status' => 'pending',
        ]);

        // Логируем для безопасности (ClickHouse если доступен)
        try {
            ClickHouseService::log('access_request_submitted', 0, [
                'tab_number' => $tabNumber,
                'fio' => $fio,
            ]);
        } catch (\Throwable $e) {
            // Не критично
        }

        // Отправляем уведомление в Telegram (для быстрого реагирования)
        $msg = "📝 *НОВАЯ ЗАЯВКА НА УЧЁТНУЮ ЗАПИСЬ*\n\n";
        $msg .= '👤 ФИО: *'.$this->escapeTelegram($fio)."*\n";
        $msg .= '🔢 Табельный номер: `'.$this->escapeTelegram($tabNumber)."`\n";
        $msg .= '🌐 IP: '.$request->ip()."\n";
        $msg .= '🕐 Время: '.now()->format('d.m.Y H:i')."\n";
        $msg .= "🆔 ID заявки: `{$accessRequest->id}`\n\n";
        $msg .= '_Проверьте и создайте учётную запись в админ-панели._';

        TelegramService::send($msg);

        // Параллельное уведомление в админку (временная мера)
        AdminNotificationService::notify(
            'access_request',
            'Новая заявка на учётную запись',
            "ФИО: {$fio}\nТабельный номер: {$tabNumber}\nIP: {$request->ip()}",
            [
                'id' => $accessRequest->id,
                'tab_number' => $tabNumber,
                'fio' => $fio,
                'ip' => $request->ip(),
            ]
        );

        Log::info('Access request submitted', [
            'id' => $accessRequest->id,
            'tab' => $tabNumber,
            'ip' => $request->ip(),
        ]);

        return back()->with('success', 'Заявка успешно отправлена! Инструктор или администратор свяжется с вами в ближайшее время.');
    }

    /**
     * Экранирование для Telegram Markdown.
     */
    private function escapeTelegram(string $text): string
    {
        return str_replace(['_', '*', '[', ']', '(', ')', '~', '`', '>', '#', '+', '-', '=', '|', '{', '}', '.', '!'],
            ['\_', '\*', '\[', '\]', '\(', '\)', '\~', '\`', '\>', '\#', '\+', '\-', '\=', '\|', '\{', '\}', '\.', '\!'],
            $text);
    }
}
