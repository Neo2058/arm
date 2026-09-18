<?php

namespace App\Http\Controllers\Naryad;

use App\Http\Middleware\EnsureDispatcher;
use Illuminate\Support\Facades\Auth;

trait EnsuresDispatcher
{
    public static function middleware(): array
    {
        return [
            EnsureDispatcher::class,
        ];
    }

    protected function abortIfNotDispatcher(): void
    {
        if (! Auth::user()?->isDispatcher()) {
            abort(403, 'Доступ только для нарядчика.');
        }
    }
}
