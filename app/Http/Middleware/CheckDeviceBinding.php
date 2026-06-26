<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Services\TelegramService;

class CheckDeviceBinding
{
    public function handle(Request $request, Closure $next)
    {
        // Never run device checks on Livewire/Filament AJAX (can cause redirects/419 during component updates and file uploads)
        if ($request->is('livewire*') || $request->is('filament*') || $request->routeIs('livewire.*')) {
            return $next($request);
        }

        if (Auth::check()) {
            $user = Auth::user();
            $role = strtolower((string)($user->role->value ?? $user->role));

            // 1. Иммунитет для администраторов
            if ($role === 'super_admin' || $role === 'admin' || str_contains($role, 'admin')) {
                return $next($request);
            }

            // 2. Исключения путей
            if (
                $request->is('device-register*') ||
                $request->is('api/device/register*') ||
                $request->is('barrier*') ||
                $request->is('api/barrier/verify*') ||
                $request->is('logout') ||
                $request->is('admin/serve-document') ||
                $request->is('admin/serve-training-material')
            ) {
                return $next($request);
            }

            // 3. Получаем хэш устройства
            $deviceKey = $request->header('X-Device-Key') ?: $request->cookie('device_key');

            if (!$deviceKey) {
                return redirect()->route('device.register.form');
            }

            // 4. Проверяем, существует ли ОДОБРЕННОЕ устройство
            $isApprovedDeviceExists = $user->devices()
                ->where('device_key', $deviceKey)
                ->where('is_approved', true)
                ->exists();

            // 5. Если одобренного устройства НЕТ — блокируем и отправляем на форму заявки
            if (!$isApprovedDeviceExists) {

                // Шлём алерт в Telegram (только если это первичный переход, а не рефреш формы)
                if (!$request->is('device-register*')) {
                    $msg = "⚠️ *ПОПЫТКА ВХОДА С НЕИЗВЕСТНОГО УСТРОЙСТВА!*\n";
                    $msg .= "👤 Сотрудник: {$user->name} (Email: {$user->email})\n";
                    $msg .= "🔑 Ключ устройства: `{$deviceKey}`\n";
                    $msg .= "🌐 IP: " . $request->ip();

                    if (class_exists(\App\Services\TelegramService::class)) {
                        TelegramService::send($msg);
                    }
                }

                return redirect()->route('device.register.form');
            }
        }

        return $next($request);
    }
}
