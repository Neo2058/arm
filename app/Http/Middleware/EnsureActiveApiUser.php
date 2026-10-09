<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveApiUser
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->fresh()?->is_active, 403, 'Аккаунт недоступен.');

        return $next($request);
    }
}
