<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Filament\Resources\UserResource\RelationManagers;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Hash;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\DatePicker;
use App\Enums\UserRole;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([

                Forms\Components\TextInput::make('name')
                    ->label('ФИО')
                    ->required(),

                Forms\Components\TextInput::make('email')
                    ->label('Email')
                    ->email()
                    ->required(),

                Forms\Components\Select::make('role')
                    ->label('Роль')
                    ->options(UserRole::options())
                    ->required(),


                Forms\Components\TextInput::make('password')
                    ->label('Пароль')
                    ->password()
                    ->revealable()
                    ->minLength(8)
                    ->dehydrateStateUsing(fn ($state) => Hash::make($state)) // ХЕШИРОВАНИЕ ПЕРЕД СОХРАНЕНИЕМ
                    ->dehydrated(fn ($state) => filled($state))
                    ->required(fn (string $context): bool => $context === 'create')
                    ->helperText('Используйте генератор ниже для создания пароля'),

                Forms\Components\View::make(
                    'filament.forms.components.vigenere-generator'
                ),

                Section::make('Личная карточка (Профиль)')
                    ->relationship('profile')
                    ->schema([

                        Grid::make(3)
                            ->schema([

                                Forms\Components\TextInput::make('tab_number')
                                    ->label('Табельный №'),

                                Forms\Components\TextInput::make('column')
                                    ->label('№ Колонны'),

                                Forms\Components\TextInput::make('instructor')
                                    ->label('ФИО Инструктора'),
                            ]),

                        Grid::make(2)
                            ->schema([

                                Forms\Components\TextInput::make('position')
                                    ->label('Должность'),

                                Forms\Components\TextInput::make('phoneNumber')
                                    ->label('Телефон')
                                    ->tel(),
                            ]),

                        Grid::make(3)
                            ->schema([

                                Forms\Components\TextInput::make('driverRoot')
                                    ->label('№ Удостоверения'),

                                DatePicker::make('dateRoot')
                                    ->label('Дата выдачи прав'),

                                DatePicker::make('birth_date')
                                    ->label('Дата рождения'),
                            ]),
                    ]),
            ])
            ->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([

                Tables\Columns\TextColumn::make('name')
                    ->label('ФИО')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('email')
                    ->label('Электронная почта')
                    ->searchable(),

                Tables\Columns\TextColumn::make('profile.column')
                    ->label('Колонна')
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('role')
                    ->label('Роль')
                    ->badge()
                    ->color(
                        fn (UserRole $state): string => $state->color()
                    )
                    ->formatStateUsing(
                        fn ($state) => UserRole::safeFrom($state)->label()
                    ),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Активен')
                    ->boolean(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Дата регистрации')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])

            ->filters([

                Tables\Filters\SelectFilter::make('role')
                    ->label('Роль')
                    ->options(UserRole::options()),

            ])

            ->actions([

                Tables\Actions\EditAction::make(),

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
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
