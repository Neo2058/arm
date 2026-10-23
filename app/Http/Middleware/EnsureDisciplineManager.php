<?php

namespace App\Http\Middleware;

use App\Services\Discipline\DisciplineAccess;
use Closure;
use Illuminate\Http\Request;

class EnsureDisciplineManager
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless($request->user(), 403);
        app(DisciplineAccess::class)->manage($request->user());

        return $next($request);
    }
}
