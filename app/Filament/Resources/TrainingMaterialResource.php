<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TrainingMaterialResource\Pages;
use App\Models\TrainingMaterial;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class TrainingMaterialResource extends Resource
{
    protected static ?string $model = TrainingMaterial::class;

    protected static ?string $navigationIcon = 'heroicon-o-video-camera';

    protected static ?string $navigationGroup = 'Обучение';

    protected static ?string $navigationLabel = 'Материалы обучения';

    protected static ?string $modelLabel = 'Материал';

    protected static ?string $pluralModelLabel = 'Материалы обучения';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('training_topic_id')
                    ->label('Тема')
                    ->relationship('topic', 'title')
                    ->searchable()
                    ->preload()
                    ->required(),

                Forms\Components\TextInput::make('title')
                    ->label('Название')
                    ->required()
                    ->maxLength(255),

                Forms\Components\Textarea::make('description')
                    ->label('Описание содержания')
                    ->rows(3)
                    ->maxLength(2000),

                Forms\Components\Select::make('type')
                    ->label('Тип материала')
                    ->options([
                        'video' => 'Видео',
                        'audio' => 'Аудио',
                        'text' => 'Текст / Выжимка',
                        'document' => 'Документ',
                    ])
                    ->required()
                    ->default('video')
                    ->live(),

                Forms\Components\FileUpload::make('file_path')
                    ->label('Файл')
                    ->disk('s3')
                    ->directory('training-materials')
                    // ВАЖНО: НЕ используем visibility('public')!
                    // Файлы приватные. Доступ через временные подписанные ссылки на прокси (через приложение).
                    ->acceptedFileTypes([
                        'video/mp4', 'video/quicktime', 'video/webm',
                        'audio/mpeg', 'audio/mp4', 'audio/ogg',
                    ])
                    ->maxSize(150 * 1024) // 150 MB
                    ->required(fn (Forms\Get $get) => in_array($get('type'), ['video', 'audio']))
                    ->hidden(fn (Forms\Get $get) => $get('type') === 'text')
                    ->helperText('Файлы загружаются приватно. Доступ предоставляется только через временные подписанные ссылки (максимальная защита от скачивания).')
                    ->getUploadedFileUsing(function (BaseFileUpload $component, string $file, string | array | null $storedFileNames): ?array {
                        $disk = Storage::disk('s3');
                        if (! $disk->exists($file)) {
                            return null;
                        }

                        // Use signed proxy URL (browser fetchable, same origin, temporary)
                        // Avoids direct MinIO (CORS / private address space / AccessDenied)
                        $url = \Illuminate\Support\Facades\URL::temporarySignedRoute(
                            'admin.training-materials.serve',
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

                Forms\Components\TextInput::make('file_name')
                    ->label('Имя файла (для отображения)')
                    ->maxLength(255)
                    ->hidden(fn (Forms\Get $get) => $get('type') === 'text'),

                Forms\Components\TextInput::make('duration')
                    ->label('Длительность (секунд)')
                    ->numeric()
                    ->minValue(0)
                    ->visible(fn (Forms\Get $get) => in_array($get('type'), ['video', 'audio'])),

                Forms\Components\Textarea::make('content')
                    ->label('Текстовое содержание / Выжимка')
                    ->rows(8)
                    ->maxLength(10000)
                    ->visible(fn (Forms\Get $get) => $get('type') === 'text')
                    ->required(fn (Forms\Get $get) => $get('type') === 'text'),

                Forms\Components\Toggle::make('is_active')
                    ->label('Активен')
                    ->default(true),

                Forms\Components\TextInput::make('sort_order')
                    ->label('Порядок сортировки')
                    ->numeric()
                    ->default(0),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('topic.title')
                    ->label('Тема')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('title')
                    ->label('Название')
                    ->searchable()
                    ->sortable()
                    ->limit(40),

                Tables\Columns\TextColumn::make('type')
                    ->label('Тип')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'video' => 'danger',
                        'audio' => 'warning',
                        'text' => 'success',
                        'document' => 'gray',
                        default => 'gray',
                    }),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Активен')
                    ->boolean(),

                Tables\Columns\TextColumn::make('duration')
                    ->label('Длительность')
                    ->formatStateUsing(fn ($state) => $state ? gmdate('i:s', $state) : '-')
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Создан')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->options([
                        'video' => 'Видео',
                        'audio' => 'Аудио',
                        'text' => 'Текст',
                        'document' => 'Документ',
                    ]),

                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Активные'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('sort_order');
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
            'index' => Pages\ListTrainingMaterials::route('/'),
            'create' => Pages\CreateTrainingMaterial::route('/create'),
            'edit' => Pages\EditTrainingMaterial::route('/{record}/edit'),
        ];
    }
}
