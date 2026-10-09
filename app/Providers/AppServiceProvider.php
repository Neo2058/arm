<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(30)->by('login-ip:'.$request->ip()),
            Limit::perMinute(5)->by('login-account:'.hash('sha256', Str::lower((string) $request->input('email')))),
        ]);

        RateLimiter::for('barrier', fn (Request $request) => [
            Limit::perMinute(30)->by('barrier-ip:'.$request->ip()),
            Limit::perHour(10)->by('barrier-user:'.$request->user()->id),
        ]);
    }
}
