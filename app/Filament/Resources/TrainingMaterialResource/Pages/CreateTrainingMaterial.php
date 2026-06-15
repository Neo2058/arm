<?php

namespace App\Filament\Resources\TrainingMaterialResource\Pages;

use App\Filament\Resources\TrainingMaterialResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTrainingMaterial extends CreateRecord
{
    protected static string $resource = TrainingMaterialResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
