<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ColumnDeputyResource\Pages;
use App\Models\ColumnDeputy;
use App\Services\Chat\ChatService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;

class ColumnDeputyResource extends Resource
{
    protected static ?string $model = ColumnDeputy::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-plus';

    protected static ?string $navigationLabel = 'Заместители';

    protected static ?string $modelLabel = 'Заместитель';

    protected static ?string $pluralModelLabel = 'Заместители колонн';

    protected static ?string $navigationGroup = 'Коммуникации';

    protected static ?int $navigationSort = 2;

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public static function canViewAny(): bool
    {
        return static::canAccess();
    }

    public static function canCreate(): bool
    {
        return static::canAccess();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canAccess();
    }

    public static function canDelete(Model $record): bool
    {
        return static::canAccess();
    }

    public static function getRecordTitle(?Model $record): string|Htmlable|null
    {
        if (! $record instanceof ColumnDeputy) {
            return parent::getRecordTitle($record);
        }

        $name = $record->user?->name ?? ('#'.$record->user_id);

        return $record->column.' / '.$name;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('user_id')
                    ->label('Сотрудник')
                    ->relationship('user', 'name')
                    ->searchable()
                    ->preload()
                    ->required()
                    ->disabledOn('edit'),
                Forms\Components\TextInput::make('column')
                    ->label('Колонна')
                    ->required()
                    ->maxLength(255)
                    ->dehydrateStateUsing(fn ($state) => is_string($state) ? trim($state) : $state)
                    ->disabledOn('edit'),
                Forms\Components\DatePicker::make('starts_on')
                    ->label('С даты')
                    ->required(),
                Forms\Components\DatePicker::make('ends_on')
                    ->label('По дату')
                    ->required()
                    ->helperText('Обе даты включительно, календарь Europe/Moscow.'),
                Forms\Components\TextInput::make('reason')
                    ->label('Причина')
                    ->maxLength(255)
                    ->placeholder('отпуск Иванова'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Сотрудник')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('column')
                    ->label('Колонна')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('starts_on')
                    ->label('С')
                    ->date('d.m.Y')
                    ->sortable(),
                Tables\Columns\TextColumn::make('ends_on')
                    ->label('По')
                    ->date('d.m.Y')
                    ->sortable(),
                Tables\Columns\TextColumn::make('reason')
                    ->label('Причина')
                    ->placeholder('—')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('grantedBy.name')
                    ->label('Выдал')
                    ->toggleable(),
            ])
            ->defaultSort('id', 'desc')
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->using(function (ColumnDeputy $record): void {
                        app(ChatService::class)->revoke($record);
                    }),
            ])
            ->bulkActions([]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListColumnDeputies::route('/'),
            'create' => Pages\CreateColumnDeputy::route('/create'),
            'edit' => Pages\EditColumnDeputy::route('/{record}/edit'),
        ];
    }
}
