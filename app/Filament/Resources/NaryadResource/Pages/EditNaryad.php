<?php

namespace App\Filament\Resources\NaryadResource\Pages;

use App\Filament\Resources\NaryadResource;
use App\Services\Naryad\NaryadIssuePublisher;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditNaryad extends EditRecord
{
    protected static string $resource = NaryadResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('rebuild')
                ->label('Собрать файлы')
                ->icon('heroicon-o-arrow-path')
                ->action(function (): void {
                    $count = app(NaryadIssuePublisher::class)->publish($this->record);
                    Notification::make()
                        ->title('Собрано личных файлов: '.$count)
                        ->success()
                        ->send();
                }),
            Actions\DeleteAction::make(),
        ];
    }

    protected function afterSave(): void
    {
        $count = app(NaryadIssuePublisher::class)->publish($this->record);
        Notification::make()
            ->title('Собрано личных файлов: '.$count)
            ->success()
            ->send();
    }
}
