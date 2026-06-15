<?php

namespace App\Filament\Resources\TrainingMaterialResource\Pages;

use App\Filament\Resources\TrainingMaterialResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditTrainingMaterial extends EditRecord
{
    protected static string $resource = TrainingMaterialResource::class;

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
