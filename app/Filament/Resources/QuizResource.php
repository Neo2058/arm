<?php

namespace App\Filament\Resources;

use App\Filament\Resources\QuizResource\Pages;
use App\Filament\Resources\QuizResource\RelationManagers;
use App\Models\Quiz;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;

class QuizResource extends Resource
{
    protected static ?string $model = Quiz::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Основная информация')
                    ->schema([
                        TextInput::make('title')->label('Название теста')->required(),
                        Forms\Components\Textarea::make('description')->label('Описание'),
                        Select::make('document_id')
                            ->label('Привязать к документу')
                            ->relationship('document', 'title') // Связь с документами из MinIO
                            ->searchable(),
                        TextInput::make('time_limit')->label('Лимит времени (мин)')->numeric()->default(30),
                        Toggle::make('is_active')->label('Активен')->default(true),
                    ])->columns(2),

                Section::make('Конструктор вопросов')
                    ->schema([
                        Repeater::make('questions') // Связь hasMany в модели Quiz
                        ->relationship()
                            ->label('Вопросы')
                            ->schema([
                                Forms\Components\Textarea::make('question_text')
                                    ->label('Текст вопроса')
                                    ->required(),

                                Forms\Components\Repeater::make('references')
                                    ->relationship()
                                    ->label('Ссылки на пункты инструкций (материал для подсказки)')
                                    ->schema([
                                        Forms\Components\Select::make('document_id')
                                            ->label('Документ из MinIO')
                                            ->relationship('document', 'title')
                                            ->required()
                                            ->searchable(),
                                        Forms\Components\TextInput::make('page_number')
                                            ->label('Страница PDF')
                                            ->numeric()
                                            ->default(1)
                                            ->required(),
                                        Forms\Components\TextInput::make('anchor_text')
                                            ->label('Текст ссылки (например: п. 5.1 Инструкции ЦШ-530)')
                                            ->required(),
                                    ])
                                    ->columns(3),

                                Repeater::make('answers') // Связь hasMany в модели Question
                                ->relationship()
                                    ->label('Варианты ответов')
                                    ->schema([
                                        TextInput::make('answer_text')->label('Текст ответа')->required(),
                                        Toggle::make('is_correct')->label('Верный'),
                                    ])
                                    ->columns(2)
                                    ->grid(2) // Компактное отображение ответов сеткой
                                    ->minItems(2) // Минимум 2 ответа
                                    ->required(),
                            ])
                            ->collapsible() // Можно сворачивать вопросы
                            ->itemLabel(fn (array $state): ?string => $state['question_text'] ?? null),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')->label('Название')->searchable(),
                Tables\Columns\TextColumn::make('document.title')->label('Документ'),
                Tables\Columns\TextColumn::make('questions_count')->counts('questions')->label('Вопросов'),
                Tables\Columns\IconColumn::make('is_active')->label('Статус')->boolean(),
                Tables\Columns\TextColumn::make('created_at')->label('Создан')->dateTime('d.m.Y'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('document_id')
                    ->label('По документу')
                    ->relationship('document', 'title'),
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

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListQuizzes::route('/'),
            'create' => Pages\CreateQuiz::route('/create'),
            'edit' => Pages\EditQuiz::route('/{record}/edit'),
        ];
    }
}
