<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;

/**
 * Общие исключения для CheckDeviceBinding и CheckDynamicBarrier.
 *
 * Livewire/Filament не снимать: /livewire/update и FileUpload ломаются редиректом.
 * admin/serve-* — signed preview FileUpload; маршруты в той же auth-группе.
 */
final class AccessBarrierExemptions
{
    public static function isLifecycleRequest(Request $request): bool
    {
        return $request->is('livewire*')
            || $request->is('filament*')
            || $request->routeIs('livewire.*');
    }

    public static function isExemptPath(Request $request): bool
    {
        return $request->is(
            'device-register*',
            'api/device/register*',
            'barrier*',
            'api/barrier/verify*',
            'logout',
            'admin/serve-document',
            'admin/serve-training-material',
        );
    }

    public static function shouldSkip(Request $request): bool
    {
        return self::isLifecycleRequest($request) || self::isExemptPath($request);
    }
}
