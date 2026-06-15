<?php

namespace App\Filament\Resources\TrainingTopicResource\Pages;

use App\Filament\Resources\TrainingTopicResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListTrainingTopics extends ListRecords
{
    protected static string $resource = TrainingTopicResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
