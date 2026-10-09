<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\AccessRequestResource;
use App\Filament\Resources\AdminNotificationResource;
use App\Filament\Resources\UserDeviceResource;
use App\Models\AccessRequest;
use App\Models\AdminNotification;
use App\Models\UserDevice;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class PendingAccessRequestsWidget extends BaseWidget
{
    protected static ?int $sort = 0;

    protected function getStats(): array
    {
        $pendingRequests = AccessRequest::query()->where('status', 'pending')->count();
        $unread = AdminNotification::query()->where('is_read', false)->count();

        $stats = [
            Stat::make('Заявки на регистрацию', $pendingRequests)
                ->description('Ожидают рассмотрения')
                ->color($pendingRequests > 0 ? 'warning' : 'success')
                ->url(AccessRequestResource::getUrl('index')),
            Stat::make('Непрочитанные уведомления', $unread)
                ->color($unread > 0 ? 'danger' : 'success')
                ->url(AdminNotificationResource::getUrl('index')),
        ];

        if (UserDeviceResource::canViewAny()) {
            $pendingDevices = UserDevice::query()->where('is_approved', false)->count();
            $stats[] = Stat::make('Устройства без доступа', $pendingDevices)
                ->description('Ждут одобрения')
                ->color($pendingDevices > 0 ? 'warning' : 'success')
                ->url(UserDeviceResource::getUrl('index'));
        }

        return $stats;
    }
}
