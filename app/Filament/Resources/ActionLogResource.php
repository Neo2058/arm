<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ActionLogResource\Pages;
use App\Models\ActionLog;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class ActionLogResource extends Resource
{
    protected static ?string $model = ActionLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationLabel = 'Логи действий пользователей';

    protected static ?string $navigationGroup = 'Администрирование';

    protected static ?int $navigationSort = 100;

    public static function canAccess(): bool
    {
        return Auth::user()?->isSuperAdmin() ?? false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Пользователь')
                    ->searchable()
                    ->sortable()
                    ->default('—'),

                Tables\Columns\TextColumn::make('action')
                    ->label('Действие')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'view_phones_tab', 'view_explanations_tab', 'view_naryads_tab' => 'info',
                        'open_naryad' => 'success',
                        'search_phones', 'search_explanations', 'search_naryad_pdf' => 'warning',
                        'add_phone', 'add_explanation' => 'success',
                        'delete_phone', 'delete_explanation' => 'danger',
                        default => 'gray',
                    })
                    ->searchable(),

                Tables\Columns\TextColumn::make('details')
                    ->label('Детали')
                    ->formatStateUsing(function ($state) {
                        if (is_array($state)) {
                            return collect($state)
                                ->map(fn ($v, $k) => "$k: $v")
                                ->implode(' | ');
                        }

                        return $state ?? '—';
                    })
                    ->wrap()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('ip_address')
                    ->label('IP')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Время')
                    ->dateTime('d.m.Y H:i:s')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('action')
                    ->label('Тип действия')
                    ->options([
                        'view_naryads_page' => 'Просмотр страницы нарядов',
                        'view_naryads_tab' => 'Вкладка «Наряды»',
                        'view_phones_tab' => 'Вкладка «Телефоны»',
                        'view_explanations_tab' => 'Вкладка «Расшифровки»',
                        'open_naryad' => 'Открытие наряда (PDF)',
                        'search_phones' => 'Поиск в телефонах',
                        'search_explanations' => 'Поиск в расшифровках',
                        'search_naryad_pdf' => 'Поиск внутри PDF',
                        'add_phone' => 'Добавление телефона',
                        'delete_phone' => 'Удаление телефона',
                        'add_explanation' => 'Добавление расшифровки',
                        'delete_explanation' => 'Удаление расшифровки',
                    ])
                    ->multiple()
                    ->searchable(),

                Tables\Filters\SelectFilter::make('user_id')
                    ->label('Пользователь')
                    ->relationship('user', 'name')
                    ->searchable()
                    ->preload(),

                Tables\Filters\Filter::make('created_at')
                    ->form([
                        DatePicker::make('created_from')->label('С даты'),
                        DatePicker::make('created_until')->label('По дату'),
                    ])
                    ->query(function ($query, array $data) {
                        return $query
                            ->when($data['created_from'], fn ($q, $date) => $q->whereDate('created_at', '>=', $date))
                            ->when($data['created_until'], fn ($q, $date) => $q->whereDate('created_at', '<=', $date));
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([
                // Read-only logs — no bulk delete for safety
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListActionLogs::route('/'),
            'view' => Pages\ViewActionLog::route('/{record}'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) static::getModel()::count();
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }
}
