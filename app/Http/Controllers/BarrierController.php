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

    /**
     * Алгоритм генерации правильной комбинации на сегодня (0, 1, 2 или 3 для каждого вопроса)
     */
    /**
     * Алгоритм генерации правильной комбинации на сегодня (улучшенная версия)
     */
    private function getDailySequence(): array
    {
        $month = (int)date('m');
        $year  = (int)date('Y');

        // Секретная соль (храни в .env!)
        $salt = config('app.barrier_salt', 'you-never-win-in-game');

        // Создаём строку, от которой будем брать хеш
        $baseString = "{$year}-{$month}-{$salt}";

        // Используем SHA-256 и берём первые 8 символов hex
        $hash = hash('sha256', $baseString);

        // Преобразуем хеш в 4 числа от 0 до 3
        return [
            hexdec(substr($hash, 0, 2))  % 4,
            hexdec(substr($hash, 2, 2))  % 4,
            hexdec(substr($hash, 4, 2))  % 4,
            hexdec(substr($hash, 6, 2))  % 4,
        ];
    }

    public function verify(Request $request)
    {
        $request->validate(['answers' => 'required|array|size:4']);

        $userAnswers = $request->input('answers');
        $correctSequence = $this->getDailySequence();

        if ($userAnswers === $correctSequence) {
            $request->session()->put('dynamic_barrier_passed', true);

            if (class_exists(\App\Services\ClickHouseService::class)) {
                ClickHouseService::log('barrier_success', 0, 'Успешный проход динамического барьера');
            }

            return response()->json(['status' => 'success', 'redirect' => '/index']);
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

