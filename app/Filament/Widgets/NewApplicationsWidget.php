<?php

namespace App\Filament\Widgets;

use App\Models\AdminNotification;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class NewApplicationsWidget extends BaseWidget
{
    protected static ?int $sort = 2;

    public static function canView(): bool
    {
        return true;
    }

    protected function getStats(): array
    {
        $pending = AdminNotification::where('type', 'access_request')
            ->where('is_read', false)
            ->count();

        $totalPending = AdminNotification::where('is_read', false)->count();

        return [
            Stat::make('Новых заявок на регистрацию', $pending)
                ->description('Непрочитанные')
                ->color($pending > 0 ? 'warning' : 'success')
                ->url(route('filament.admin.resources.admin-notifications.index') . '?type=access_request'),
            
            Stat::make('Всего непрочитанных уведомлений', $totalPending)
                ->color($totalPending > 0 ? 'danger' : 'success'),
        ];
    }
}
