<?php

namespace App\Filament\Resources\ActionLogResource\Pages;

use App\Filament\Resources\ActionLogResource;
use Filament\Resources\Pages\ViewRecord;
use Filament\Infolists\Infolist;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;

class ViewActionLog extends ViewRecord
{
    protected static string $resource = ActionLogResource::class;

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Section::make('Информация о действии')
                    ->schema([
                        TextEntry::make('user.name')->label('Пользователь'),
                        TextEntry::make('action')->label('Действие')->badge(),
                        TextEntry::make('details')
                            ->label('Детали')
                            ->formatStateUsing(fn ($state) => is_array($state) ? json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : $state)
                            ->columnSpanFull(),
                        TextEntry::make('ip_address')->label('IP адрес'),
                        TextEntry::make('user_agent')->label('User Agent')->columnSpanFull(),
                        TextEntry::make('created_at')->label('Время')->dateTime(),
                    ])->columns(2),
            ]);
    }
}