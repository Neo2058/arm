<?php

namespace App\Http\Controllers;

use App\Models\AccessRequest;
use App\Services\AdminNotificationService;
use App\Services\ClickHouseService;
use App\Services\TelegramService;
use Illuminate\Http\Request;
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
    public function submit(Request $request)
    {
        // Honeypot защита от ботов
        if ($request->filled('website')) {
            Log::warning('Potential bot detected in access request', [
                'ip' => $request->ip(),
                'ua' => $request->userAgent(),
            ]);
            return back()->with('success', 'Заявка принята. Ожидайте ответа.'); // Не раскрываем
        }

        $validated = $request->validate([
            'tab_number' => [
                'required',
                'string',
                'min:4',
                'max:20',
                'regex:/^[0-9A-Za-z\-]+$/',
            ],
            'fio' => [
                'required',
                'string',
                'min:5',
                'max:150',
                'regex:/^[\p{L}\s\-\.]+$/u', // Только буквы (включая кириллицу), пробелы, дефисы, точки
            ],
        ], [
            'tab_number.required' => 'Укажите табельный номер.',
            'tab_number.regex' => 'Табельный номер может содержать только цифры, буквы и дефис.',
            'fio.required' => 'Укажите ФИО полностью.',
            'fio.regex' => 'ФИО должно содержать только буквы, пробелы, дефисы и точки.',
            'fio.min' => 'ФИО слишком короткое.',
        ]);

        // Дополнительная защита: очистка (хотя Laravel escape'ит в Blade)
        $tabNumber = trim(strip_tags($validated['tab_number']));
        $fio = trim(strip_tags($validated['fio']));

        // Проверка на дубликаты за последние 24 часа (анти-спам)
        $recent = AccessRequest::where('tab_number', $tabNumber)
            ->where('created_at', '>=', now()->subDay())
            ->exists();

        if ($recent) {
            return back()->with('success', 'Заявка с таким табельным номером уже находится на рассмотрении. Повторно подавать не требуется.');
        }

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
        $msg .= "👤 ФИО: *" . $this->escapeTelegram($fio) . "*\n";
        $msg .= "🔢 Табельный номер: `" . $this->escapeTelegram($tabNumber) . "`\n";
        $msg .= "🌐 IP: " . $request->ip() . "\n";
        $msg .= "🕐 Время: " . now()->format('d.m.Y H:i') . "\n";
        $msg .= "🆔 ID заявки: `{$accessRequest->id}`\n\n";
        $msg .= "_Проверьте и создайте учётную запись в админ-панели._";

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
