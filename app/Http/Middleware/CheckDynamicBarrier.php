<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CheckDynamicBarrier
{
    /**
     * Задача этого Middleware — только проверять факт прохождения барьера
     */
    public function handle(Request $request, Closure $next)
    {
        // КРИТИЧЕСКИ ВАЖНО: Если пользователь НЕ авторизован (гость),
        // этот посредник вообще не должен работать! Пропускаем его на логин.
        if (!auth()->check()) {
            return $next($request);
        }

        $user = Auth::user();
        $role = strtolower((string)($user->role->value ?? $user->role));

        // Пропускаем админов мимо барьера автоматически
        if ($role === 'super_admin' || $role === 'admin' || str_contains($role, 'admin')) {
            return $next($request);
        }

        // 1. Если барьер уже пройден в этой сессии — беспрепятственно пускаем дальше
        if ($request->session()->get('dynamic_barrier_passed')) {
            return $next($request);
        }

        // ИСПОЛЬЗУЕМ ИМЕНА МАРШРУТОВ ДЛЯ ИСКЛЮЧЕНИЙ:
        if (
            $request->is('barrier*') ||
            $request->is('api/barrier/verify*') ||
            $request->is('device-register*') ||
            $request->is('api/device/register*') ||
            $request->is('logout')
        ) {
            return $next($request);
        }

        // 3. Если барьер не пройден и адрес запроса не входит в список исключений — отправляем на барьер
        return redirect()->route('barrier.show');
    }
}
