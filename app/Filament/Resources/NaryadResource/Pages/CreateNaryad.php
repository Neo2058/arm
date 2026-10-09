<?php

namespace App\Filament\Resources\NaryadResource\Pages;

use App\Filament\Resources\NaryadResource;
use App\Services\Naryad\NaryadIssuePublisher;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateNaryad extends CreateRecord
{
    protected static string $resource = NaryadResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['uploaded_by'] = auth()->id();

        return $data;
    }

    protected function afterCreate(): void
    {
        $count = app(NaryadIssuePublisher::class)->publish($this->record);
        Notification::make()
            ->title('Собрано личных файлов: '.$count)
            ->success()
            ->send();
    }
}
