<?php

namespace App\Filament\Resources\TrainingTopicResource\Pages;

use App\Filament\Resources\TrainingTopicResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTrainingTopic extends CreateRecord
{
    protected static string $resource = TrainingTopicResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
