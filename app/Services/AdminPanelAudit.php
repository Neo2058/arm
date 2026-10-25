<?php

namespace App\Services;

use App\Models\AccessRequest;
use App\Models\ActionLog;
use App\Models\ChatRoom;
use App\Models\ColumnDeputy;
use App\Models\DeviationsCatalog;
use App\Models\Document;
use App\Models\FormularTask;
use App\Models\InstructionCategory;
use App\Models\Naryad;
use App\Models\Quiz;
use App\Models\RoutesCatalog;
use App\Models\TrainingMaterial;
use App\Models\TrainingTopic;
use App\Models\User;
use App\Models\UserDevice;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;

class AdminPanelAudit
{
    /**
     * @var list<class-string<Model>>
     */
    public const MODELS = [
        User::class,
        AccessRequest::class,
        UserDevice::class,
        Document::class,
        Quiz::class,
        TrainingTopic::class,
        TrainingMaterial::class,
        InstructionCategory::class,
        FormularTask::class,
        RoutesCatalog::class,
        DeviationsCatalog::class,
        Naryad::class,
        ChatRoom::class,
        ColumnDeputy::class,
    ];

    /**
     * @var list<string>
     */
    private const SKIP_FIELDS = ['updated_at', 'remember_token'];

    public static function register(): void
    {
        foreach (self::MODELS as $model) {
            $model::created(fn (Model $record) => self::record($record, 'created'));
            $model::updated(fn (Model $record) => self::record($record, 'updated'));
            $model::deleted(fn (Model $record) => self::record($record, 'deleted'));
        }
    }

    public static function isAdminPanelRequest(): bool
    {
        try {
            $panel = Filament::getCurrentPanel();
        } catch (\Throwable) {
            return false;
        }

        if ($panel?->getId() !== 'admin' || ! Filament::isServing()) {
            return false;
        }

        $user = Filament::auth()->user();

        return $user instanceof User && $user->canAccessPanel($panel);
    }

    public static function record(Model $record, string $verb): void
    {
        if (! self::isAdminPanelRequest()) {
            return;
        }

        if ($verb === 'updated') {
            $changed = self::changedFields($record);
            if ($changed === []) {
                return;
            }
        }

        $base = match ($record::class) {
            User::class => 'user',
            AccessRequest::class => 'access_request',
            UserDevice::class => 'user_device',
            Document::class => 'document',
            Quiz::class => 'quiz',
            TrainingTopic::class => 'training_topic',
            TrainingMaterial::class => 'training_material',
            InstructionCategory::class => 'instruction_category',
            FormularTask::class => 'formular_task',
            RoutesCatalog::class => 'routes_catalog',
            DeviationsCatalog::class => 'deviations_catalog',
            Naryad::class => 'naryad',
            ChatRoom::class => 'chat_room',
            ColumnDeputy::class => 'column_deputy',
            default => strtolower(class_basename($record)),
        };

        ActionLog::log('admin.'.$base.'.'.$verb, self::details($record, $verb));
    }

    /**
     * @return list<string>
     */
    public static function changedFields(Model $record): array
    {
        return array_values(array_diff(array_keys($record->getChanges()), self::SKIP_FIELDS));
    }

    /**
     * @return array<string, mixed>
     */
    public static function details(Model $record, string $verb): array
    {
        $details = [
            'id' => $record->getKey(),
        ];

        foreach (['name', 'title', 'fio', 'device_name'] as $field) {
            $value = $record->getAttribute($field);
            if (is_scalar($value) && $value !== '') {
                $details[$field] = $value;
            }
        }

        if ($record instanceof User) {
            $details['email'] = $record->email;
            $details['role'] = $record->roleValue();
            $details['is_active'] = (bool) $record->is_active;
        }

        if ($verb === 'updated') {
            $details['changed'] = self::changedFields($record);
        }

        return $details;
    }
}
