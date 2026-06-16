<?php

namespace App\Filament\Resources\InstructionCategoryResource\Pages;

use App\Filament\Resources\InstructionCategoryResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListInstructionCategories extends ListRecords
{
    protected static string $resource = InstructionCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
