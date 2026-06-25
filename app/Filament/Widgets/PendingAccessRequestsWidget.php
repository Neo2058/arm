<?php

namespace App\Filament\Widgets;

use App\Models\AccessRequest;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class PendingAccessRequestsWidget extends BaseWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $pendingCount = AccessRequest::where('status', 'pending')->count();

        return [
            Stat::make('Новых заявок', $pendingCount)
                ->description('Ожидают рассмотрения')
                ->color($pendingCount > 0 ? 'warning' : 'success')
                ->url('/admin/access-requests?status=pending'),
        ];
    }
}
