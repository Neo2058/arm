<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureDispatcher
{
    /**
     * Планирование наряда доступно только роли dispatcher
     * (legacy-алиас naryadchik нормализуется в UserRole::safeFrom).
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->isDispatcher()) {
            abort(403, 'Доступ только для нарядчика.');
        }

        return $next($request);
    }
}
