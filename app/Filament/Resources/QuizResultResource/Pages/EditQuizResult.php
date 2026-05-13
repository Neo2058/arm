<?php

namespace App\Filament\Resources\QuizResultResource\Pages;

use App\Filament\Resources\QuizResultResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditQuizResult extends EditRecord
{
    protected static string $resource = QuizResultResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
}
