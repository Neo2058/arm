<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DocumentResource\Pages;
use App\Models\Document;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;

class DocumentResource extends Resource
{
    protected static ?string $model = Document::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationLabel = 'Документы';


    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('title')
                    ->label('Название документа')
                    ->required(),

                Select::make('category')
                    ->label('Категория')
                    ->options([
                        'manual' => 'Руководство',
                        'order' => 'Приказ',
                        'technical' => 'Тех. документация',
                    ]),

                FileUpload::make('file_path')
                    ->label('Файл (PDF, DOCX)')
                    ->disk('s3') // Указываем, что файл летит в MinIO
                    ->directory('uploads/teaching') // Папка внутри бакета
                    ->visibility('private') // Файлы недоступны по прямой ссылке (безопасность)
                    ->acceptedFileTypes(['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'])
                    ->required()
                    ->preserveFilenames() // Сохранять оригинальное имя файла
                    ->maxSize(10240), // Ограничение 10МБ

                Select::make('allowed_roles')
                    ->label('Доступно для ролей')
                    ->multiple() // Позволяет выбрать несколько ролей сразу
                    ->options([
                        'super_admin' => 'Супер админ',
                        'admin' => 'Админ',
                        'instructor' => 'Инструктор',
                        'driver' => 'Машинист',
                        'student' => 'Обучающийся',
                    ])
                    ->placeholder('Если пусто — доступно ВСЕМ')
                    ->required(false),

                Select::make('instruction_category_id')
                    ->label('Папка для инструктажей / росписей')
                    ->relationship('instructionCategory', 'name')
                    ->searchable()
                    ->preload()
                    ->nullable()
                    ->placeholder('Не для инструктажей')
                    ->helperText('Выберите папку — документ автоматически попадёт в раздел Росписи для пользователей.'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')->label('Название')->searchable(),
                Tables\Columns\TextColumn::make('category')->label('Категория')->badge(),
                Tables\Columns\TextColumn::make('instructionCategory.name')->label('Папка инструктажа')->sortable(),
                Tables\Columns\TextColumn::make('file_path')
                    ->label('Размер')
                    ->formatStateUsing(function ($state) {
                        if (empty($state)) {
                            return '—';
                        }
                        try {
                            $bytes = Storage::disk('s3')->size($state);
                            return round($bytes / 1024 / 1024, 2) . ' MB';
                        } catch (\Throwable $e) {
                            \Log::warning('S3 file size lookup failed', [
                                'path' => $state,
                                'error' => $e->getMessage(),
                            ]);
                            return 'N/A';
                        }
                    }),
                Tables\Columns\TextColumn::make('created_at')->label('Загружен')->dateTime(),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                // Добавим кнопку скачивания для проверки
                Tables\Actions\Action::make('download')
                    ->label('Скачать')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->url(function ($record) {
                        if (empty($record->file_path)) return null;
                        try {
                            return Storage::disk('s3')->temporaryUrl($record->file_path, now()->addMinutes(5));
                        } catch (\Throwable $e) {
                            \Log::warning('S3 temporaryUrl failed', ['path' => $record->file_path, 'error' => $e->getMessage()]);
                            return null;
                        }
                    })
                    ->openUrlInNewTab(),
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
            'index' => Pages\ListDocuments::route('/'),
            'create' => Pages\CreateDocument::route('/create'),
            'edit' => Pages\EditDocument::route('/{record}/edit'),
        ];
    }
}
