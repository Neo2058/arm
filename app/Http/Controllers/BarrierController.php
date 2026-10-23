<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\TelegramService;
use App\Services\ClickHouseService;
use Illuminate\Support\Facades\Auth;

class BarrierController extends Controller
{
    public function show()
    {
        // Проверяем, если барьер уже пройден в этой сессии, незачем его показывать снова
        if (request()->session()->get('dynamic_barrier_passed')) {
            return redirect()->route('mainMenu');
        }

        return view('auth.barrier');
    }

    public function verify(Request $request)
    {
        $request->validate([
            'answers' => 'required|array|size:4',
            'answers.*' => 'required|integer|between:0,3',
        ]);

        $userAnswers = $request->input('answers');
        $correctSequence = app(\App\Services\MonthlyAccessKey::class)->sequence();

        if ($userAnswers === $correctSequence) {
            $request->session()->put('dynamic_barrier_passed', true);

            if (class_exists(\App\Services\ClickHouseService::class)) {
                ClickHouseService::log('barrier_success', 0, 'Успешный проход динамического барьера');
            }

            return response()->json(['status' => 'success', 'redirect' => route('mainMenu')]);
        }

        // Логируем попытку взлома
        $user = Auth::user();
        $ip = $request->ip();

        $msg = "🚨 *ПОПЫТКА ВЗЛОМА БАРЬЕРА!*\n";
        $msg .= "👤 Пользователь: " . ($user ? $user->name : 'Аноним') . "\n";
        $msg .= "🌐 IP: {$ip}\n";
        $msg .= "🔏 Введенные индексы: " . implode(', ', $userAnswers);

        if (class_exists(\App\Services\TelegramService::class)) {
            TelegramService::send($msg);
        }

        if (class_exists(\App\Services\ClickHouseService::class)) {
            ClickHouseService::log('barrier_failed', 0, ['ip' => $ip, 'input' => $userAnswers]);
        }

        return response()->json(['status' => 'error', 'message' => 'Доступ заблокирован. Неверный протокол ввода.'], 403);
    }
}
