<?php

namespace App\Filament\Resources\ColumnDeputyResource\Pages;

use App\Filament\Resources\ColumnDeputyResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListColumnDeputies extends ListRecords
{
    protected static string $resource = ColumnDeputyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
