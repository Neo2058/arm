<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * We exclude Livewire endpoints because:
     * - They are same-origin, session-protected.
     * - File uploads use additional signed URLs (hasValidSignature).
     * - This prevents 419 "page expired" issues that are very common
     *   with Filament file uploads + Docker/nginx + custom domains (especially IDN/punycode).
     * - The component snapshot + auth already provide strong protection.
     */
    protected $except = [
        'livewire/*',
        'livewire/update',
        'livewire/upload-file',
    ];
}
