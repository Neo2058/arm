<?php

namespace App\Providers\Filament;

use App\Filament\Discipline\Pages\DisciplineMonitor;
use App\Http\Middleware\CheckDeviceBinding;
use App\Http\Middleware\CheckDynamicBarrier;
use App\Http\Middleware\CheckUserExistence;
use App\Http\Middleware\EnsureDisciplineManager;
use App\Http\Middleware\VerifyCsrfToken;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class DisciplinePanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        // No discovery: discipline roles never inherit unrestricted admin resources/widgets.
        return $panel->id('discipline')->path('discipline-admin')->login()
            ->brandName('Исполнительская дисциплина')
            ->colors(['primary' => Color::Amber])
            ->pages([DisciplineMonitor::class])
            ->middleware([
                EncryptCookies::class, AddQueuedCookiesToResponse::class, StartSession::class,
                AuthenticateSession::class, ShareErrorsFromSession::class, VerifyCsrfToken::class,
                SubstituteBindings::class, DisableBladeIconComponents::class, DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class, CheckUserExistence::class, EnsureDisciplineManager::class,
                CheckDeviceBinding::class, CheckDynamicBarrier::class,
            ], isPersistent: true);
    }
}
