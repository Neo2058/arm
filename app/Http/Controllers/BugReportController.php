<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\BugReport;
use App\Services\TelegramService;


class BugReportController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'description' => 'required|string|max:1000',
            'screenshot' => 'nullable|image|max:5120', // Макс 5МБ
            'page_url' => 'nullable|string'
        ]);

        $user = auth()->user();
        $path = null;

        // Если прикрепили скриншот — отправляем его в MinIO в бакет документов
        if ($request->hasFile('screenshot')) {
            $path = $request->file('screenshot')->store('bug-reports', 's3');
        }

        $report = BugReport::create([
            'user_id' => $user->id,
            'description' => $request->description,
            'screenshot_path' => $path,
            'page_url' => $request->page_url,
        ]);

        // Формируем красивый текст для Телеграма
        $msg = "🪲 *НОВЫЙ БАГ-РЕПОРТ / ОТЗЫВ*\n\n";
        $msg .= "👤 От кого: *{$user->name}* (" . ($user->role->value ?? $user->role) . ")\n";
        $msg .= "📍 Страница: {$request->page_url}\n";
        $msg .= "📝 Описание:\n_{$request->description}_";

        if ($path) {
            TelegramService::sendPhoto($path, null, $msg, 'Markdown');
        } else {
            TelegramService::send($msg, null, 'Markdown');
        }

        return response()->json(['status' => 'success', 'message' => 'Спасибо! Ваш отзыв успешно отправлен разработчику.']);
    }
}
