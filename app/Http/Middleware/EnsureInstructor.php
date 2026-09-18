<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureInstructor
{
    /**
     * Журнал ТЧМ доступен только роли instructor.
     * Один guard на все методы TCHMJournalController /journal*.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->isInstructor()) {
            abort(403, 'Доступ только для инструкторов (ТЧМ).');
        }

        return $next($request);
    }
}
