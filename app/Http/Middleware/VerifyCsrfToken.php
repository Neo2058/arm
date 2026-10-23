<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    // Only this machine endpoint uses Telegram secret authentication instead of browser CSRF.
    protected $except = ['telegram/webhook'];

    // Livewire and Filament use the same CSRF protection as other web requests.
    // Client-controlled headers must never disable token verification.
}
