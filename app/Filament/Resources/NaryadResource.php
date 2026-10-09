<?php

namespace App\Filament\Resources;

use App\Enums\UserRole;
use App\Filament\Resources\NaryadResource\Pages;
use App\Models\Naryad;
use App\Services\Naryad\NaryadIssuePublisher;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class NaryadResource extends Resource
{
    protected static ?string $model = Naryad::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-arrow-up';

    protected static ?string $navigationLabel = 'Выкладка нарядов';

    protected static ?string $modelLabel = 'Выкладка наряда';

    protected static ?string $pluralModelLabel = 'Выкладка нарядов';

    protected static ?string $navigationGroup = 'Документы';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Выкладка')
                    ->description('Общий PDF для всех и TXT-наряды с разбивкой смен — как в программе searching. Планирование сетки сюда не входит.')
                    ->schema([
                        Forms\Components\TextInput::make('title')
                            ->label('Название')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\DatePicker::make('naryad_date')
                            ->label('Дата наряда')
                            ->required(),
                        Forms\Components\Select::make('allowed_roles')
                            ->label('Кому видно')
                            ->multiple()
                            ->options(UserRole::options())
                            ->placeholder('Если пусто — всем ролям'),
                    ])->columns(3),

                Forms\Components\Section::make('Общий наряд (PDF)')
                    ->description('Файл без отбора по фамилиям. Его видит каждый на странице нарядов.')
                    ->schema([
                        Forms\Components\FileUpload::make('file_path')
                            ->label('PDF')
                            ->disk('s3')
                            ->directory('naryads')
                            ->acceptedFileTypes(['application/pdf'])
                            ->required()
                            ->visibility('private'),
                    ]),

                Forms\Components\Section::make('Исходники searching')
                    ->description('Текстовые наряды (1.txt…31.txt) и таблицы разбивки рабочих/выходных. По ним собираются личные файлы на ФИО пользователей.')
                    ->schema([
                        Forms\Components\FileUpload::make('source_files')
                            ->label('Наряды TXT')
                            ->disk('s3')
                            ->directory('naryads/sources')
                            ->multiple()
                            ->acceptedFileTypes(['text/plain', 'text/csv', 'application/octet-stream'])
                            ->visibility('private')
                            ->required()
                            ->helperText('Файлы наряда в кодировке CP866 или UTF-8.'),
                        Forms\Components\FileUpload::make('work_shifts_path')
                            ->label('Разбивка рабочих дней')
                            ->disk('s3')
                            ->directory('naryads/shifts')
                            ->acceptedFileTypes([
                                'text/plain',
                                'application/pdf',
                                'application/octet-stream',
                            ])
                            ->visibility('private'),
                        Forms\Components\FileUpload::make('weekend_shifts_path')
                            ->label('Разбивка выходных')
                            ->disk('s3')
                            ->directory('naryads/shifts')
                            ->acceptedFileTypes([
                                'text/plain',
                                'application/pdf',
                                'application/octet-stream',
                            ])
                            ->visibility('private'),
                    ])->columns(1),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')->label('Название')->searchable(),
                Tables\Columns\TextColumn::make('naryad_date')->label('Дата')->date('d.m.Y')->sortable(),
                Tables\Columns\TextColumn::make('person_files_count')
                    ->counts('personFiles')
                    ->label('Личных файлов'),
                Tables\Columns\TextColumn::make('created_at')->label('Загружена')->dateTime('d.m.Y H:i'),
            ])
            ->defaultSort('naryad_date', 'desc')
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('rebuild')
                    ->label('Собрать файлы')
                    ->icon('heroicon-o-arrow-path')
                    ->requiresConfirmation()
                    ->action(function (Naryad $record): void {
                        $count = app(NaryadIssuePublisher::class)->publish($record);
                        Notification::make()
                            ->title('Собрано личных файлов: '.$count)
                            ->success()
                            ->send();
                    }),
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
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListNaryads::route('/'),
            'create' => Pages\CreateNaryad::route('/create'),
            'edit' => Pages\EditNaryad::route('/{record}/edit'),
        ];
    }
}
