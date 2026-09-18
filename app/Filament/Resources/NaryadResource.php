<?php

namespace App\Filament\Resources;

use App\Enums\UserRole;
use App\Filament\Resources\NaryadResource\Pages;
use App\Models\Naryad;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class NaryadResource extends Resource
{
    protected static ?string $model = Naryad::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationLabel = 'Наряды (PDF)';

    protected static ?string $modelLabel = 'Наряд';

    protected static ?string $pluralModelLabel = 'Наряды';

    protected static ?string $navigationGroup = 'Документы';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('title')
                    ->label('Название наряда')
                    ->required()
                    ->maxLength(255),
                Forms\Components\DatePicker::make('naryad_date')
                    ->label('Дата наряда')
                    ->required(),
                Forms\Components\FileUpload::make('file_path')
                    ->label('PDF файл')
                    ->disk('s3')
                    ->directory('naryads')
                    ->acceptedFileTypes(['application/pdf'])
                    ->required()
                    ->visibility('private'),
                Forms\Components\Select::make('allowed_roles')
                    ->label('Доступные роли')
                    ->multiple()
                    ->options(UserRole::options())
                    ->nullable(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')->label('Название')->searchable(),
                Tables\Columns\TextColumn::make('naryad_date')->label('Дата')->date()->sortable(),
                Tables\Columns\TextColumn::make('created_at')->label('Загружен')->dateTime(),
            ])
            ->filters([
                //
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
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListNaryads::route('/'),
            'create' => Pages\CreateNaryad::route('/create'),
            'edit' => Pages\EditNaryad::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }
}
