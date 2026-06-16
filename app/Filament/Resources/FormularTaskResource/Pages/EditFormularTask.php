<?php

namespace App\Filament\Resources\FormularTaskResource\Pages;

use App\Filament\Resources\FormularTaskResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditFormularTask extends EditRecord
{
    protected static string $resource = FormularTaskResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
