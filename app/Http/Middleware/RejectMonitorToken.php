<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class RejectMonitorToken
{
    public function handle(Request $request, Closure $next)
    {
        $token = $request->user()?->currentAccessToken();
        abort_if($token instanceof PersonalAccessToken && $token->name === 'monitor', 403);

        return $next($request);
    }
}
