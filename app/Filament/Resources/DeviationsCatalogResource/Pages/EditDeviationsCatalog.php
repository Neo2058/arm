<?php

namespace App\Filament\Resources\DeviationsCatalogResource\Pages;

use App\Filament\Resources\DeviationsCatalogResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditDeviationsCatalog extends EditRecord
{
    protected static string $resource = DeviationsCatalogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
