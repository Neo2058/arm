<?php

namespace App\Filament\Resources\QuizResultResource\Pages;

use App\Filament\Resources\QuizResultResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewQuizResult extends ViewRecord
{
    protected static string $resource = QuizResultResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }
}
