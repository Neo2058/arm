<?php

namespace App\Http\Middleware;

use App\Services\TelegramService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CheckDeviceBinding
{
    public function handle(Request $request, Closure $next)
    {
        if (AccessBarrierExemptions::isLifecycleRequest($request)) {
            return $next($request);
        }

        if (Auth::check()) {
            $user = Auth::user();

            if ($user->canBypassAccessBarriers()) {
                return $next($request);
            }

            if (AccessBarrierExemptions::isExemptPath($request)) {
                return $next($request);
            }

            $deviceKey = $request->header('X-Device-Key') ?: $request->cookie('device_key');

            if (! $deviceKey) {
                return redirect()->route('device.register.form');
            }

            $isApprovedDeviceExists = $user->devices()
                ->where('device_key', $deviceKey)
                ->where('is_approved', true)
                ->exists();

            if (! $isApprovedDeviceExists) {
                $msg = "⚠️ *ПОПЫТКА ВХОДА С НЕИЗВЕСТНОГО УСТРОЙСТВА!*\n";
                $msg .= "👤 Сотрудник: {$user->name} (Email: {$user->email})\n";
                $msg .= "🔑 Ключ устройства: `{$deviceKey}`\n";
                $msg .= '🌐 IP: '.$request->ip();

                if (class_exists(TelegramService::class)) {
                    TelegramService::send($msg);
                }

                return redirect()->route('device.register.form');
            }
        }

        return $next($request);
    }
}
