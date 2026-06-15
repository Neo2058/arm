<?php

namespace App\Filament\Resources\TrainingMaterialResource\Pages;

use App\Filament\Resources\TrainingMaterialResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListTrainingMaterials extends ListRecords
{
    protected static string $resource = TrainingMaterialResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
