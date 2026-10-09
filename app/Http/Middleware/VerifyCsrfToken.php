<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    // Livewire and Filament use the same CSRF protection as other web requests.
    // Client-controlled headers must never disable token verification.
}
