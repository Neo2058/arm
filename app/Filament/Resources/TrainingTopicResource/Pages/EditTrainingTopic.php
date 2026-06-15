<?php

namespace App\Filament\Resources\TrainingTopicResource\Pages;

use App\Filament\Resources\TrainingTopicResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditTrainingTopic extends EditRecord
{
    protected static string $resource = TrainingTopicResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
