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
        if (! auth()->check()) {
            return $next($request);
        }

        // Never run barrier/device checks during Livewire updates or Filament component lifecycle (file uploads etc).
        // These are pure AJAX; a redirect here breaks Livewire and causes 419 / reload loops.
        if ($request->is('livewire*') || $request->is('filament*') || $request->routeIs('livewire.*')) {
            return $next($request);
        }

        $user = Auth::user();

        // Пропускаем админов мимо барьера автоматически
        if ($user->canBypassAccessBarriers()) {
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
            $request->is('logout') ||
            $request->is('admin/serve-document') ||
            $request->is('admin/serve-training-material')
        ) {
            return $next($request);
        }

        // 3. Если барьер не пройден и адрес запроса не входит в список исключений — отправляем на барьер
        return redirect()->route('barrier.show');
    }
}
