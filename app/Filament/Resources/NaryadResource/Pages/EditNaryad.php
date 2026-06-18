<?php

namespace App\Filament\Resources\NaryadResource\Pages;

use App\Filament\Resources\NaryadResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditNaryad extends EditRecord
{
    protected static string $resource = NaryadResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
