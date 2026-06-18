<?php

namespace App\Filament\Resources\ActionLogResource\Pages;

use App\Filament\Resources\ActionLogResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Actions;

class ListActionLogs extends ListRecords
{
    protected static string $resource = ActionLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('stats')
                ->label('Перейти к статистике')
                ->icon('heroicon-o-chart-bar')
                ->color('warning')
                ->url(\App\Filament\Pages\UserActivityStats::getUrl()),
        ];
    }
}