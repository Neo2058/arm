<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DeviationsCatalogResource\Pages;
use App\Models\DeviationsCatalog;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class DeviationsCatalogResource extends Resource
{
    protected static ?string $model = DeviationsCatalog::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationLabel = 'Каталог отвлечений';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')->label('Название отвлечения')->required()->placeholder('Например: Военные сборы'),
                Forms\Components\TextInput::make('sys_key')->label('Системный уникальный ключ')->required()->placeholder('military'),
                Forms\Components\TextInput::make('hourly_rate')->label('Тарифная часовая ставка (руб/час)')->numeric()->required()->default(0),
                Forms\Components\TextInput::make('default_minutes')->label('Стандартная длительность дня (мин)')->numeric()->required()->default(480),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Название отвлечения')->searchable(),
                Tables\Columns\TextColumn::make('sys_key')->label('Ключ'),
                Tables\Columns\TextColumn::make('hourly_rate')->label('Ставка (руб/ч)')->money('RUB', locale: 'ru'),
                Tables\Columns\TextColumn::make('default_minutes')->label('Длительность (ч.)')->formatStateUsing(fn ($state) => ($state / 60).' ч.'),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDeviationsCatalogs::route('/'),
            'create' => Pages\CreateDeviationsCatalog::route('/create'),
            'edit' => Pages\EditDeviationsCatalog::route('/{record}/edit'),
        ];
    }
}
