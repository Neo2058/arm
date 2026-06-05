<?php

namespace App\Filament\Resources\RoutesCatalogResource\Pages;

use App\Filament\Resources\RoutesCatalogResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListRoutesCatalogs extends ListRecords
{
    protected static string $resource = RoutesCatalogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
