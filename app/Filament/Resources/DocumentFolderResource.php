<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DocumentFolderResource\Pages;
use App\Models\DocumentFolder;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class DocumentFolderResource extends Resource
{
    protected static ?string $model = DocumentFolder::class;

    protected static ?string $navigationIcon = 'heroicon-o-folder';

    protected static ?string $navigationLabel = 'Разделы документов';

    protected static ?string $navigationGroup = 'Документы';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')->label('Название')->required()->maxLength(255),
            Forms\Components\Select::make('category')->label('Основная категория')->options(DocumentFolder::CATEGORIES)
                ->required()->live()->disabledOn('edit')->dehydrated()
                ->afterStateUpdated(fn (Forms\Set $set) => $set('parent_id', null)),
            Forms\Components\Select::make('parent_id')->label('Родительская папка')->nullable()->searchable()
                ->options(fn (Forms\Get $get) => DocumentFolder::where('category', $get('category'))->orderBy('name')->get()
                    ->mapWithKeys(fn ($folder) => [$folder->id => implode(' / ', array_column($folder->path(), 'name'))])->all())
                ->disabledOn('edit')->dehydrated()->helperText('До 6 уровней. Родитель и категория после создания фиксируются; документы можно перемещать между папками.'),
        ]);
    }

    public static function canViewAny(): bool
    {
        return (bool) (auth()->user()?->is_active && auth()->user()?->isAdmin());
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('name')->label('Название')->searchable(),
            Tables\Columns\TextColumn::make('category')->label('Категория'),
            Tables\Columns\TextColumn::make('parent.name')->label('Родитель'),
        ])->actions([Tables\Actions\EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListDocumentFolders::route('/'), 'create' => Pages\CreateDocumentFolder::route('/create'), 'edit' => Pages\EditDocumentFolder::route('/{record}/edit')];
    }
}
