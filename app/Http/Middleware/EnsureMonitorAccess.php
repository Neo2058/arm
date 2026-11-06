<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class EnsureMonitorAccess
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user()?->fresh();
        $token = $request->user()?->currentAccessToken();
        abort_unless($user?->is_active && $user->isSuperAdmin() && $token instanceof PersonalAccessToken, 403);
        $installer = $request->routeIs('monitor.installer*');
        abort_unless($installer
            ? $token->name === 'mobile' && $token->can('*')
            : $token->name === 'monitor' && $token->can('monitor:read'), 403);
        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
