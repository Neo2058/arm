<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AdminNotificationResource\Pages;
use App\Models\AdminNotification;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AdminNotificationResource extends Resource
{
    protected static ?string $model = AdminNotification::class;

    protected static ?string $navigationIcon = 'heroicon-o-bell';

    protected static ?string $navigationLabel = 'Уведомления';

    protected static ?string $modelLabel = 'Уведомление';

    protected static ?string $pluralModelLabel = 'Уведомления';

    protected static ?int $navigationSort = 5;

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::unreadCount() > 0 
            ? (string) static::getModel()::unreadCount() 
            : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return static::getModel()::unreadCount() > 0 ? 'warning' : null;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('type')
                    ->label('Тип')
                    ->disabled(),
                Forms\Components\TextInput::make('title')
                    ->label('Заголовок')
                    ->disabled(),
                Forms\Components\Textarea::make('message')
                    ->label('Сообщение')
                    ->disabled()
                    ->columnSpanFull(),
                Forms\Components\KeyValue::make('data')
                    ->label('Данные')
                    ->disabled(),
                Forms\Components\Toggle::make('is_read')
                    ->label('Прочитано'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('type')
                    ->label('Тип')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'access_request' => 'info',
                        'device_request' => 'warning',
                        'bug_report' => 'danger',
                        'backstage' => 'success',
                        default => 'gray',
                    })
                    ->searchable(),
                Tables\Columns\TextColumn::make('title')
                    ->label('Заголовок')
                    ->searchable()
                    ->limit(50),
                Tables\Columns\TextColumn::make('message')
                    ->label('Сообщение')
                    ->limit(80)
                    ->wrap(),
                Tables\Columns\IconColumn::make('is_read')
                    ->label('Прочитано')
                    ->boolean(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Дата')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->label('Тип')
                    ->options([
                        'access_request' => 'Заявка на регистрацию',
                        'device_request' => 'Заявка на устройство',
                        'bug_report' => 'Баг-репорт',
                        'backstage' => 'Обратная связь',
                    ]),
                Tables\Filters\TernaryFilter::make('is_read')
                    ->label('Прочитано'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('mark_read')
                    ->label('Отметить прочитанным')
                    ->icon('heroicon-o-check')
                    ->action(fn (AdminNotification $record) => $record->update(['is_read' => true]))
                    ->visible(fn (AdminNotification $record) => !$record->is_read),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('mark_all_read')
                        ->label('Отметить прочитанными')
                        ->action(fn ($records) => $records->each->update(['is_read' => true])),
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAdminNotifications::route('/'),
            'view' => Pages\ViewAdminNotification::route('/{record}'),
        ];
    }
}
