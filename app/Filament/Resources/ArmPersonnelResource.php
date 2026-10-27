<?php

namespace App\Filament\Resources;

use App\Models\ArmPersonnel;
use App\Services\Arm\PersonnelDirectory;
use App\Services\ClickHouseService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class ArmPersonnelResource extends Resource
{
    protected static ?string $model = ArmPersonnel::class;

    protected static ?string $navigationIcon = 'heroicon-o-identification';

    protected static ?string $navigationLabel = 'Картотека машинистов';

    protected static ?string $modelLabel = 'Карточка машиниста';

    protected static ?string $pluralModelLabel = 'Картотека машинистов';

    protected static ?string $navigationGroup = 'Люди и доступ';

    public static function canViewAny(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('tab_number')->label('Табельный номер')->disabled(),
            Forms\Components\TextInput::make('column')->label('Колонна')->maxLength(32)->afterStateHydrated(fn ($component, $record) => $component->state($record?->user?->profile?->column)),
            Forms\Components\TextInput::make('full_name')->label('ФИО')->required()->maxLength(255),
            Forms\Components\TextInput::make('position_code')->label('Должность')->maxLength(8),
            Forms\Components\TextInput::make('class_code')->label('Класс')->maxLength(4),
            Forms\Components\TextInput::make('phone_primary')->label('Телефон')->maxLength(16),
            Forms\Components\TextInput::make('phone_secondary')->label('Дополнительный телефон')->maxLength(16),
            Forms\Components\TextInput::make('brigade_code')->label('Бригада')->maxLength(8),
            Forms\Components\Toggle::make('is_brigadier')->label('Бригадир'),
            Forms\Components\DatePicker::make('hired_on')->label('Дата приёма'),
            Forms\Components\DatePicker::make('fired_on')->label('Дата увольнения')->disabled(),
            Forms\Components\DatePicker::make('med_from')->label('Медкомиссия с'),
            Forms\Components\DatePicker::make('med_to')->label('Медкомиссия до'),
            Forms\Components\DatePicker::make('seniority_on')->label('Начало выслуги машиниста'),
            Forms\Components\DatePicker::make('assistant_seniority_on')->label('Начало выслуги помощника'),
            Forms\Components\TextInput::make('assistant_seniority_years')->label('Выслуга помощника, лет')->numeric()->required()->minValue(0)->maxValue(999),
            Forms\Components\DatePicker::make('brigadier_from')->label('Бригадир с'),
            Forms\Components\DatePicker::make('brigadier_to')->label('Бригадир до'),
            Forms\Components\TextInput::make('shop_code')->label('Цех')->maxLength(8),
            Forms\Components\TextInput::make('roster_number')->label('Номер в списке')->maxLength(8),
            Forms\Components\TextInput::make('category_code')->label('Категория')->maxLength(4),
            Forms\Components\TextInput::make('premium_flag')->label('Отметка премии')->maxLength(4),
            Forms\Components\TextInput::make('main_tab_number')->label('Основной табельный')->maxLength(8),
            Forms\Components\TextInput::make('depo_code')->label('Депо')->maxLength(8),
            Forms\Components\Repeater::make('early_windows')->label('Ранние заступления')->maxItems(3)->schema([
                Forms\Components\TextInput::make('time')->label('Время, ЧЧ.ММ')->maxLength(5),
                Forms\Components\TextInput::make('reason')->label('Основание')->maxLength(8),
            ])->columns(2)->columnSpanFull(),
            Forms\Components\Repeater::make('late_windows')->label('Поздние окончания')->maxItems(3)->schema([
                Forms\Components\TextInput::make('time')->label('Время, ЧЧ.ММ')->maxLength(5),
                Forms\Components\TextInput::make('reason')->label('Основание')->maxLength(8),
            ])->columns(2)->columnSpanFull(),
            Forms\Components\KeyValue::make('source_data')->label('Исходные поля FoxPro')->disabled()->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('tab_number')->label('Табельный')->searchable()->sortable(),
            Tables\Columns\TextColumn::make('full_name')->label('ФИО')->searchable()->sortable(),
            Tables\Columns\TextColumn::make('user.profile.column')->label('Колонна')->searchable(),
            Tables\Columns\TextColumn::make('position_code')->label('Должность')->sortable(),
            Tables\Columns\TextColumn::make('class_code')->label('Класс'),
            Tables\Columns\TextColumn::make('phone_primary')->label('Телефон')->searchable(),
            Tables\Columns\TextColumn::make('brigade_code')->label('Бригада')->sortable(),
            Tables\Columns\TextColumn::make('fired_on')->label('Уволен')->date('d.m.Y')->sortable(),
        ])->filters([
            Tables\Filters\Filter::make('working')->label('Только работающие')->query(fn ($query) => $query->where(fn ($q) => $q->whereNull('fired_on')->orWhereDate('fired_on', '>=', today()))),
        ])->actions([
            Tables\Actions\ViewAction::make(),
            Tables\Actions\EditAction::make()->using(function (ArmPersonnel $record, array $data): ArmPersonnel {
                $person = app(PersonnelDirectory::class)->update(auth()->user(), $record->id, $data);
                ClickHouseService::log('personnel.updated', $person->id, ['fields' => array_keys($data)]);

                return $person;
            }),
        ])->bulkActions([
            Tables\Actions\BulkAction::make('assignColumn')->label('Назначить колонну')
                ->form([Forms\Components\TextInput::make('column')->label('Колонна')->required()->maxLength(32)])
                ->requiresConfirmation()->deselectRecordsAfterCompletion()
                ->action(function (Collection $records, array $data): void {
                    abort_unless(static::canViewAny(), 403);
                    DB::transaction(function () use ($records, $data) {
                        foreach ($records as $record) {
                            app(PersonnelDirectory::class)->update(auth()->user(), $record->id, ['column' => $data['column']]);
                        }
                    });
                    ClickHouseService::log('personnel.column_assigned', 0, ['count' => $records->count(), 'column' => $data['column']]);
                }),
        ])->defaultSort('tab_number');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('user.profile');
    }

    public static function getPages(): array
    {
        return ['index' => ArmPersonnelResource\Pages\ListArmPersonnel::route('/')];
    }
}
