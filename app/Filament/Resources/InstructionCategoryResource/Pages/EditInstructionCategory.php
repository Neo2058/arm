<?php

namespace App\Filament\Resources\InstructionCategoryResource\Pages;

use App\Filament\Resources\InstructionCategoryResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditInstructionCategory extends EditRecord
{
    protected static string $resource = InstructionCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
