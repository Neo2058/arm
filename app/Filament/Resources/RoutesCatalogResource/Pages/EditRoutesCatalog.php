<?php

namespace App\Filament\Resources\RoutesCatalogResource\Pages;

use App\Filament\Resources\RoutesCatalogResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditRoutesCatalog extends EditRecord
{
    protected static string $resource = RoutesCatalogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
