<?php

namespace App\Filament\Resources;

use App\Enums\ChatMemberRole;
use App\Enums\ChatMessageStatus;
use App\Enums\ChatMessageType;
use App\Enums\ChatType;
use App\Filament\Resources\ChatRoomResource\Pages;
use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\Chat\ChatCrypto;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Infolists\Components\Section as InfoSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class ChatRoomResource extends Resource
{
    public const SUBTITLE = 'Содержимое доступно администратору депо';

    protected static ?string $model = ChatRoom::class;

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?string $navigationLabel = 'Чаты';

    protected static ?string $modelLabel = 'Комната';

    protected static ?string $pluralModelLabel = 'Комнаты';

    protected static ?string $navigationGroup = 'Коммуникации';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'title';

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

    public static function canView(Model $record): bool
    {
        return static::canAccess();
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    /**
     * @return array<string, string>
     */
    public static function typeOptions(): array
    {
        return [
            ChatType::COLUMN->value => 'Колонна',
            ChatType::LEADERSHIP->value => 'Руководители',
            ChatType::WORK->value => 'Рабочий',
        ];
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Комната')
                    ->description(self::SUBTITLE)
                    ->schema([
                        Forms\Components\TextInput::make('title')
                            ->label('Название')
                            ->required()
                            ->maxLength(120),
                        Forms\Components\Select::make('type')
                            ->label('Тип')
                            ->options(self::typeOptions())
                            ->required()
                            ->live()
                            ->disabledOn('edit'),
                        Forms\Components\TextInput::make('column')
                            ->label('Колонна')
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->dehydrateStateUsing(fn ($state) => is_string($state) ? trim($state) : $state)
                            ->required(fn (Get $get): bool => self::formType($get('type')) === ChatType::COLUMN)
                            ->visible(fn (Get $get): bool => self::formType($get('type')) !== ChatType::LEADERSHIP)
                            ->helperText(function (Get $get): ?string {
                                $type = self::formType($get('type'));
                                if ($type === ChatType::WORK) {
                                    return 'Подпись в списке, не влияет на доступ.';
                                }
                                if ($type !== ChatType::COLUMN) {
                                    return null;
                                }
                                $column = trim((string) $get('column'));
                                if ($column === '') {
                                    return 'Точное совпадение с профилем (user_profiles.column).';
                                }
                                $exists = UserProfile::query()->whereRaw('trim(column) = ?', [$column])->exists();

                                return $exists
                                    ? 'Точное совпадение с профилем (user_profiles.column).'
                                    : 'Внимание: нет профилей с колонной «'.$column.'» (точное совпадение).';
                            }),
                        Forms\Components\Toggle::make('archived')
                            ->label('Архивирован')
                            ->helperText('Удалить комнату нельзя — только архив.')
                            ->default(false),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Участники')
                    ->description('Для чата колонны активные инструкторы и текущие заместители добавятся сами. Руководители — только вручную.')
                    ->schema([
                        Forms\Components\Repeater::make('members')
                            ->label('Состав')
                            ->schema([
                                Forms\Components\Select::make('user_id')
                                    ->label('Сотрудник')
                                    ->searchable()
                                    ->preload()
                                    ->options(fn () => User::query()
                                        ->where('is_active', true)
                                        ->orderBy('name')
                                        ->pluck('name', 'id'))
                                    ->required()
                                    ->distinct()
                                    ->disableOptionsWhenSelectedInSiblingRepeaterItems(),
                                Forms\Components\Select::make('role')
                                    ->label('Роль')
                                    ->options([
                                        ChatMemberRole::MEMBER->value => 'Участник',
                                        ChatMemberRole::MODERATOR->value => 'Модератор',
                                    ])
                                    ->default(ChatMemberRole::MEMBER->value)
                                    ->required(),
                            ])
                            ->columns(2)
                            ->defaultItems(0)
                            ->addActionLabel('Добавить участника')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->label('Название')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('type')
                    ->label('Тип')
                    ->badge()
                    ->formatStateUsing(fn (ChatType|string $state): string => self::typeOptions()[self::formType($state)?->value ?? (string) $state] ?? (string) $state),
                Tables\Columns\TextColumn::make('column')
                    ->label('Колонна')
                    ->placeholder('—')
                    ->searchable(),
                Tables\Columns\IconColumn::make('archived_at')
                    ->label('Архив')
                    ->boolean()
                    ->getStateUsing(fn (ChatRoom $record): bool => $record->archived_at !== null),
                Tables\Columns\TextColumn::make('members_count')
                    ->counts('members')
                    ->label('Участников'),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Создана')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->label('Тип')
                    ->options(self::typeOptions()),
                Tables\Filters\TernaryFilter::make('archived_at')
                    ->label('Архив')
                    ->nullable()
                    ->trueLabel('Архивные')
                    ->falseLabel('Активные'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                InfoSection::make('Комната')
                    ->description(self::SUBTITLE)
                    ->schema([
                        TextEntry::make('title')->label('Название'),
                        TextEntry::make('type')
                            ->label('Тип')
                            ->formatStateUsing(fn (ChatType|string $state): string => self::typeOptions()[self::formType($state)?->value ?? (string) $state] ?? (string) $state),
                        TextEntry::make('column')->label('Колонна')->placeholder('—'),
                        TextEntry::make('archived_at')
                            ->label('Архив')
                            ->dateTime('d.m.Y H:i')
                            ->placeholder('нет'),
                        TextEntry::make('members_count')
                            ->label('Участников')
                            ->state(fn (ChatRoom $record): int => $record->members()->count()),
                    ])
                    ->columns(3),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListChatRooms::route('/'),
            'create' => Pages\CreateChatRoom::route('/create'),
            'view' => Pages\ViewChatRoom::route('/{record}'),
            'edit' => Pages\EditChatRoom::route('/{record}/edit'),
        ];
    }

    public static function presentFeedBody(ChatMessage $message): string
    {
        if ($message->status === ChatMessageStatus::QUEUED) {
            return '';
        }
        if ($message->status === ChatMessageStatus::REJECTED) {
            return 'Отклонено антивирусом';
        }

        return match ($message->type) {
            ChatMessageType::IMAGE => 'Фото',
            ChatMessageType::PDF => 'PDF',
            default => self::decryptBody($message),
        };
    }

    public static function decryptBody(ChatMessage $message): string
    {
        $cipher = $message->body_ciphertext;
        if (! is_string($cipher) || $cipher === '') {
            return '';
        }

        return app(ChatCrypto::class)->decrypt($cipher);
    }

    public static function formType(mixed $type): ?ChatType
    {
        if ($type instanceof ChatType) {
            return $type;
        }

        return ChatType::tryFrom((string) $type);
    }
}
