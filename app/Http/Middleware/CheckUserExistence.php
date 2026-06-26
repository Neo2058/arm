<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CheckUserExistence
{
    public function handle(Request $request, Closure $next)
    {
        // Never interfere with Livewire updates or Filament internal calls (prevents 419 / session destroy during AJAX)
        if ($request->is('livewire*') || $request->is('filament*') || $request->routeIs('livewire.*')) {
            return $next($request);
        }

        // Проверяем только авторизованных пользователей
        if (Auth::check()) {
            $user = Auth::user();

            // Делаем быстрый запрос в базу, чтобы проверить, существует ли пользователь и активен ли он
            // Использование fresh() принудительно перечитывает данные из БД в обход кеша сессии
            if (!$user || !$user->fresh() || !$user->is_active) {

                // Если пользователь удален или заблокирован — мгновенно уничтожаем сессию
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                // Отправляем на логин с сообщением
                return redirect()->route('login')->withErrors([
                    'email' => 'Ваш аккаунт был удален или деактивирован администратором.'
                ]);
            }
        }

        return $next($request);
    }
}
