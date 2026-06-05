<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserDeviceResource\Pages;
use App\Filament\Resources\UserDeviceResource\RelationManagers;
use App\Models\UserDevice;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;

class UserDeviceResource extends Resource
{
    protected static ?string $model = UserDevice::class;

    // Иконка в боковом меню Filament
    protected static ?string $navigationIcon = 'heroicon-o-device-phone-mobile';
    protected static ?string $navigationLabel = 'Устройства сотрудников';
    protected static ?string $modelLabel = 'Устройство';
    protected static ?string $pluralModelLabel = 'Устройства';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('user_id')
                    ->relationship('user', 'name')
                    ->label('Сотрудник')
                    ->required()
                    ->searchable(),
                Forms\Components\TextInput::make('device_name')
                    ->label('Название оборудования')
                    ->required(),
                Forms\Components\TextInput::make('device_key')
                    ->label('Хэш-ключ устройства')
                    ->required()
                    ->disabled(), // Запрещаем редактировать сам хэш
                Forms\Components\Toggle::make('is_approved')
                    ->label('Доступ одобрен')
                    ->default(false),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // Выводим имя сотрудника из связанной таблицы users
                TextColumn::make('user.name')
                    ->label('Сотрудник')
                    ->searchable()
                    ->sortable(),

                // Достаем номер колонны из профиля сотрудника
                TextColumn::make('user.profile.column')
                    ->label('Колонна')
                    ->sortable()
                    ->placeholder('—'),

                TextColumn::make('device_name')
                    ->label('Оборудование')
                    ->searchable(),

                TextColumn::make('device_key')
                    ->label('Ключ (хэш)')
                    ->fontFamily('mono')
                    ->copyable() // Позволяет скопировать хэш в один клик
                    ->toggleable(isToggledHiddenByDefault: true),

                // Самый важный тумблер: инструктор кликает прямо в таблице, и доступ открыт!
                ToggleColumn::make('is_approved')
                    ->label('Статус доступа')
                    ->onColor('success')
                    ->offColor('danger'),

                TextColumn::make('created_at')
                    ->label('Дата запроса')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->filters([
                // Фильтр для быстрого поиска только новых (неодобренных) заявок
                Tables\Filters\Filter::make('is_pending')
                    ->label('Ожидают подтверждения')
                    ->query(fn ($query) => $query->where('is_approved', false)),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUserDevices::route('/'),
            'create' => Pages\CreateUserDevice::route('/create'),
            'edit' => Pages\EditUserDevice::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        $user = auth()->user();
        if (!$user) return false;

        // Безопасно достаем текстовую роль
        $role = strtolower((string)($user->role->value ?? $user->role));

        // Доступ имеют ТОЛЬКО супер-админы и админы
        return in_array($role, ['super_admin', 'student']);
    }
}
