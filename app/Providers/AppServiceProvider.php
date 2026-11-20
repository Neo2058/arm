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

        // Laravel `throttle:N,M` keys authenticated users by id only, so inbox/chat polls
        // would share a bucket with Telegram link issue (5 / 10 minutes) and return 429.
        RateLimiter::for('telegram-link', function (Request $request) {
            return Limit::perMinutes(10, 5)
                ->by((string) ($request->user()?->id ?: $request->ip()))
                ->response(function (Request $request, array $headers) {
                    $minutes = max(1, (int) ceil(((int) ($headers['Retry-After'] ?? 60)) / 60));
                    $message = "Слишком много попыток привязки Telegram. Подождите {$minutes} мин. и повторите.";
                    if ($request->expectsJson()) {
                        return response()->json(['message' => $message], 429, $headers);
                    }

                    return redirect()->route('telegram.link.show')->with('error', $message);
                });
        });
    }
}
