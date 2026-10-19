<?php

namespace App\Providers;

use App\Listeners\LogAuthenticationEvents;
use App\Services\AdminPanelAudit;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
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
        Event::listen(Login::class, [LogAuthenticationEvents::class, 'login']);
        Event::listen(Failed::class, [LogAuthenticationEvents::class, 'failed']);
        Event::listen(Logout::class, [LogAuthenticationEvents::class, 'logout']);
        AdminPanelAudit::register();

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
