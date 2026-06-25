<?php

use App\Http\Middleware\CheckDeviceBinding;
use App\Http\Middleware\CheckDynamicBarrier;
use App\Http\Middleware\CheckUserExistence;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

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
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
