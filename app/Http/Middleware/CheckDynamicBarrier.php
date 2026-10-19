<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CheckDynamicBarrier
{
    /**
     * Задача этого Middleware — только проверять факт прохождения барьера
     */
    public function handle(Request $request, Closure $next)
    {
        if (! auth()->check()) {
            return $next($request);
        }

        if (AccessBarrierExemptions::isLifecycleRequest($request)) {
            return $next($request);
        }

        $user = Auth::user();

        if ($user->canBypassAccessBarriers()) {
            return $next($request);
        }

        if ($request->session()->get('dynamic_barrier_passed')) {
            return $next($request);
        }

        if (AccessBarrierExemptions::isExemptPath($request)) {
            return $next($request);
        }

        return redirect()->route('barrier.show');
    }
}
