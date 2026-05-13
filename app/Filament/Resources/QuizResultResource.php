<?php

namespace App\Filament\Resources;

use App\Filament\Resources\QuizResultResource\Pages;
use App\Filament\Resources\QuizResultResource\RelationManagers;
use App\Models\QuizResult;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Infolists\Infolist;

class QuizResultResource extends Resource
{
    protected static ?string $model = QuizResult::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                //
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
             // Данные пользователя через связь
            TextColumn::make('user.name')
                ->label('ФИО')
                ->searchable()
                ->sortable(),

            // Достаем табельный номер из профиля (если связь настроена)
            TextColumn::make('user.profile.tab_number')
                ->label('Табельный №')
                ->placeholder('Не указан'),

            TextColumn::make('quiz.title')
                ->label('Тест')
                ->sortable(),

            // Результат в виде дроби (например, 8/10)
            TextColumn::make('score_display')
                ->label('Результат')
                ->state(fn ($record): string => "{$record->score} / {$record->total_questions}")
                ->badge()
                ->color(fn ($record) => ($record->score / $record->total_questions) >= 0.8 ? 'success' : 'danger'),

            // Время прохождения (переводим секунды в минуты)
            TextColumn::make('time_spent')
                ->label('Время')
                ->formatStateUsing(fn ($state) => floor($state / 60) . ' мин ' . ($state % 60) . ' сек'),

            TextColumn::make('created_at')
                ->label('Дата прохождения')
                ->dateTime('d.m.Y H:i')
                ->sortable(),
            ])
            ->filters([
            Tables\Filters\SelectFilter::make('quiz_id')
                ->label('Фильтр по тесту')
                ->relationship('quiz', 'title'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label('Детали'), // Позволит посмотреть лог ответов
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                \Filament\Infolists\Components\Section::make('Детальный отчет')
                    ->schema([
                        \Filament\Infolists\Components\TextEntry::make('user.name')->label('Сотрудник'),
                        \Filament\Infolists\Components\KeyValueEntry::make('answers_log')
                            ->label('Лог ответов (ID вопроса -> ID ответа)'),
                    ])
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
            'index' => Pages\ListQuizResults::route('/'),
            'create' => Pages\CreateQuizResult::route('/create'),
            'view' => Pages\ViewQuizResult::route('/{record}'),
            'edit' => Pages\EditQuizResult::route('/{record}/edit'),
        ];
    }
}
