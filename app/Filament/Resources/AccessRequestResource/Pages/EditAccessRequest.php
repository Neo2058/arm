<?php

namespace App\Filament\Resources\AccessRequestResource\Pages;

use App\Filament\Resources\AccessRequestResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditAccessRequest extends EditRecord
{
    protected static string $resource = AccessRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
