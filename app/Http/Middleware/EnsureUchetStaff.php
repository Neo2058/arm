<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUchetStaff
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || ! $user->isUchetStaff()) {
            abort(403, 'Доступ только для оператора учёта или нарядчика.');
        }

        return $next($request);
    }
}
