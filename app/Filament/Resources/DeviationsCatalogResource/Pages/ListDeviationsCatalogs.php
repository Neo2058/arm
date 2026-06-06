<?php

namespace App\Filament\Resources\DeviationsCatalogResource\Pages;

use App\Filament\Resources\DeviationsCatalogResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListDeviationsCatalogs extends ListRecords
{
    protected static string $resource = DeviationsCatalogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
