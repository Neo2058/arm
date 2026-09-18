<?php

namespace App\Filament\Resources;

use App\Enums\UserRole;
use App\Filament\Resources\DocumentResource\Pages;
use App\Models\Document;
use Filament\Forms\Components\BaseFileUpload;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

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
                        'manual' => 'Инструкции',
                        'order' => 'П по Депо',
                        'orderForMetro' => 'П по М',
                        'instruction' => 'И по Депо',
                        'instForMetro' => 'И по М',
                        'technical' => 'Конспекты',
                        'student' => 'Конспект ТУ',
                        'remember' => 'Памятки',
                    ]),

                FileUpload::make('file_path')
                    ->label('Файл (PDF, DOCX)')
                    ->disk('s3') // Указываем, что файл летит в MinIO
                    ->directory('uploads/teaching') // Папка внутри бакета
                    ->visibility('private') // Файлы недоступны по прямой ссылке (безопасность)
                    ->acceptedFileTypes(['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'])
                    ->required()
                    ->preserveFilenames() // Сохранять оригинальное имя файла
                    ->maxSize(20240) // Ограничение 10МБ
                    // Provide browser-fetchable preview URL via our signed admin proxy (prevents direct MinIO fetch errors / 419 related UI issues)
                    ->getUploadedFileUsing(function (BaseFileUpload $component, $file, string|array|null $storedFileNames): ?array {
                        $file = (string) $file;
                        $disk = Storage::disk('s3');
                        if (! $disk->exists($file)) {
                            return null;
                        }

                        $url = URL::temporarySignedRoute(
                            'admin.documents.serve',
                            now()->addMinutes(15),
                            ['path' => $file]
                        );

                        return [
                            'name' => ($component->isMultiple() ? ($storedFileNames[$file] ?? null) : $storedFileNames) ?? basename($file),
                            'size' => $disk->size($file),
                            'type' => $disk->mimeType($file),
                            'url' => $url,
                        ];
                    }),

                Select::make('allowed_roles')
                    ->label('Доступно для ролей')
                    ->multiple() // Позволяет выбрать несколько ролей сразу
                    ->options(UserRole::options())
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

                            return round($bytes / 1024 / 1024, 2).' MB';
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
                // Скачивание (только для админов; для остальных - нарушение с алертами)
                Tables\Actions\Action::make('download')
                    ->label('Скачать')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->url(fn (Document $record) => route('documents.download', $record))
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
