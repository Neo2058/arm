<?php

namespace App\Filament\Resources\AdminNotificationResource\Pages;

use App\Filament\Resources\AdminNotificationResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewAdminNotification extends ViewRecord
{
    protected static string $resource = AdminNotificationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('mark_read')
                ->label('Отметить прочитанным')
                ->action(fn () => $this->record->update(['is_read' => true]))
                ->visible(fn () => !$this->record->is_read)
                ->color('success'),
        ];
    }
}
