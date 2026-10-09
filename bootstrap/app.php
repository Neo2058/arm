<?php

use App\Http\Middleware\CheckUserExistence;
use App\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            CheckUserExistence::class, // 1. Проверка бана/удаления (для всех web роутов)
        ], replace: [
            ValidateCsrfToken::class => VerifyCsrfToken::class,
        ]);
        $middleware->encryptCookies(except: [
            'device_key',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
