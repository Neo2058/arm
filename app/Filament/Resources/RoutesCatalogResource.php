<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RoutesCatalogResource\Pages;
use App\Models\RoutesCatalog;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Grid;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Builder;

class RoutesCatalogResource extends Resource
{
    protected static ?string $model = RoutesCatalog::class;

    protected static ?string $navigationIcon = 'heroicon-o-map';
    protected static ?string $navigationLabel = 'Каталог маршрутов';
    protected static ?string $modelLabel = 'Шаблон маршрута';
    protected static ?string $pluralModelLabel = 'Каталог маршрутов';
    protected static ?string $navigationGroup = 'Управление временем'; // Сгруппируем для порядка

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Основные параметры')
                    ->schema([
                        Forms\Components\Select::make('schedule_type_id')
                            ->label('Тип графика')
                            ->relationship('scheduleType', 'name')
                            ->required()
                            ->searchable()
                            ->preload(),

                        Forms\Components\TextInput::make('route_number')
                            ->label('Номер маршрута')
                            ->placeholder('Например: 25')
                            ->required()
                            ->maxLength(255),

                        Forms\Components\Select::make('shift_type')
                            ->label('Тип смены')
                            ->options([
                                '1' => '1-с ночи',
                                '2' => '2-ранняя',
                                '3' => '3-вечёрка',
                                '3+' => '3+-ранняя ночь',
                                '4+' => '4+-ночь',
                                '5+' => '5+-поздняя ночь',
                            ])
                            ->required()
                            ->native(false),
                    ])->columns(1),

                Section::make('Параметры стандартного расписания')
                    ->schema([
                        Grid::make(2)->schema([
                            Forms\Components\TextInput::make('start_location')
                                ->label('Станция / Пункт явки')
                                ->required(),
                            Forms\Components\TimePicker::make('default_start_time')
                                ->label('Стандартное время явки')
                                ->required(),
                        ]),

                        Grid::make(2)->schema([
                            Forms\Components\TextInput::make('end_location')
                                ->label('Станция / Пункт сдачи')
                                ->required(),
                            Forms\Components\TimePicker::make('default_end_time')
                                ->label('Стандартное время сдачи')
                                ->required(),
                        ]),

                        Forms\Components\TextInput::make('default_break_duration')
                            ->label('Время отдыха / Перерыв (в минутах)')
                            ->numeric()
                            ->default(0)
                            ->required(),
                    ])->columns(1),

                Section::make('Технологическая карта смены')
                    ->schema([
                        Forms\Components\Textarea::make('technological_tasks')
                            ->label('План обязательных работ на маршруте')
                            ->placeholder("Например:\n1. Приемка локомотива и осмотр ТО-1\n2. Минута готовности\n3. Выполнение регламента переговоров при отправлении\n4. Полное опробование автотормозов")
                            ->rows(6),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('scheduleType.name')
                    ->label('Тип графика')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('route_number')
                    ->label('Номер маршрута')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('shift_type')
                    ->label('Тип смены')
                    ->formatStateUsing(function ($state) {
                        $map = [
                            '1' => '1-с ночи',
                            '2' => '2-ранняя',
                            '3' => '3-вечёрка',
                            '3+' => '3+-ранняя ночь',
                            '4+' => '4+-ночь',
                            '5+' => '5+-поздняя ночь',
                        ];
                        return $map[$state] ?? $state;
                    })
                    ->searchable()
                    ->sortable(),

                TextColumn::make('start_location')
                    ->label('Отправление')
                    ->description(fn ($record) => substr($record->default_start_time, 0, 5)),

                TextColumn::make('end_location')
                    ->label('Прибытие')
                    ->description(fn ($record) => substr($record->default_end_time, 0, 5)),

                TextColumn::make('default_break_duration')
                    ->label('Перерыв')
                    ->formatStateUsing(fn ($state) => "{$state} мин."),
            ])
            ->filters([])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->modifyQueryUsing(fn (Builder $query) => $query->with('scheduleType'));
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
            'index' => Pages\ListRoutesCatalogs::route('/'),
            'create' => Pages\CreateRoutesCatalog::route('/create'),
            'edit' => Pages\EditRoutesCatalog::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        $user = auth()->user();
        if (!$user) return false;

        $role = strtolower((string)($user->role->value ?? $user->role));
        return in_array($role, ['super_admin', 'admin', 'naryadchik', 'dispatcher']);
    }

}
