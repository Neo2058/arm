<?php

namespace App\Filament\Resources\AdminNotificationResource\Pages;

use App\Filament\Resources\AdminNotificationResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListAdminNotifications extends ListRecords
{
    protected static string $resource = AdminNotificationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('mark_all_read')
                ->label('Отметить все как прочитанные')
                ->action(function () {
                    AdminNotificationResource::getModel()::where('is_read', false)->update(['is_read' => true]);
                })
                ->requiresConfirmation()
                ->color('success'),
        ];
    }
}
