<?php

use App\Http\Middleware\CheckDeviceBinding;
use App\Http\Middleware\CheckDynamicBarrier;
use App\Http\Middleware\CheckUserExistence;
use App\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as BaseVerifyCsrfToken;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            CheckUserExistence::class, // 1. Проверка бана/удаления (для всех web роутов)
        ]);
        $middleware->encryptCookies(except: [
            'device_key',
        ]);

        // Use our custom VerifyCsrfToken that excludes Livewire routes.
        // This fixes persistent 419 errors on /livewire/update and /livewire/upload-file
        // after file uploads in Filament (common with nginx, IDN domains, large payloads, session nuances).
        $middleware->replace(BaseVerifyCsrfToken::class, VerifyCsrfToken::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
