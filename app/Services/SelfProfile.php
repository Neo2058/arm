<?php

namespace App\Services;

use App\Models\User;
use App\Services\Arm\PersonnelDirectory;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class SelfProfile
{
    public function __construct(private PersonnelDirectory $directory) {}

    public function fields(User $user): array
    {
        $fields = [
            'full_name' => ['ФИО', 'text', 255],
            'phone_primary' => ['Основной телефон', 'tel', 16],
            'birth_date' => ['Дата рождения', 'date'],
            'driverRoot' => ['Номер удостоверения', 'text', 255],
            'dateRoot' => ['Дата выдачи удостоверения', 'date'],
            'additional_notes' => ['Примечания', 'text', 500],
        ];
        if ($user->personnel()->exists()) {
            $fields += [
                'phone_secondary' => ['Дополнительный телефон', 'tel', 16],
                'position_code' => ['Должность', 'text', 8],
                'class_code' => ['Класс', 'text', 4],
                'brigade_code' => ['Бригада', 'text', 8],
                'shop_code' => ['Цех', 'text', 8],
                'hired_on' => ['Дата приёма', 'date'],
                'seniority_on' => ['Начало выслуги машиниста', 'date'],
                'assistant_seniority_on' => ['Начало выслуги помощника', 'date'],
                'assistant_seniority_years' => ['Выслуга помощника, лет', 'number'],
                'med_from' => ['Медкомиссия с', 'date'],
                'med_to' => ['Медкомиссия до', 'date'],
                'is_brigadier' => ['Бригадир', 'boolean'],
                'brigadier_from' => ['Бригадир с', 'date'],
                'brigadier_to' => ['Бригадир до', 'date'],
                'roster_number' => ['Номер в списке', 'text', 8],
                'category_code' => ['Категория', 'text', 4],
                'premium_flag' => ['Отметка премии', 'text', 4],
                'main_tab_number' => ['Основной табельный подработчика', 'text', 8],
                'depo_code' => ['Депо', 'text', 8],
                'early_windows' => ['Ранние заступления', 'windows'],
                'late_windows' => ['Поздние окончания', 'windows'],
            ];
        }

        return collect($fields)->map(fn ($f, $key) => ['key' => $key, 'label' => $f[0], 'type' => $f[1], 'max_length' => $f[2] ?? null])->values()->all();
    }

    public function rules(User $actor): array
    {
        $rules = $this->directory->rules($actor) + [
            'birth_date' => 'sometimes|nullable|date|before_or_equal:today',
            'driverRoot' => 'sometimes|nullable|string|max:255',
            'dateRoot' => 'sometimes|nullable|date',
            'additional_notes' => 'sometimes|nullable|string|max:500',
        ];
        foreach (['role', 'is_active', 'column', 'instructor', 'email', 'tab_number', 'user_id', 'fired_on', 'source_data', 'password'] as $key) {
            $rules[$key] = 'missing';
        }
        $allowed = array_column($this->fields($actor), 'key');
        foreach ($rules as $key => $rule) {
            if (! str_contains($key, '.') && ! in_array($key, $allowed)) {
                $rules[$key] = 'missing';
            }
        }

        return $rules;
    }

    public function payload(User $user): array
    {
        $user->load(['profile', 'personnel']);
        $values = [];
        foreach ($this->fields($user) as $field) {
            $key = $field['key'];
            $value = match ($key) {
                'full_name' => $user->name,
                'birth_date', 'dateRoot', 'driverRoot', 'additional_notes' => $user->profile?->$key,
                'phone_primary' => $user->personnel?->phone_primary ?? $user->profile?->phoneNumber,
                default => $user->personnel?->$key,
            };
            $values[$key] = $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value;
        }

        return [
            'user' => $user->only(['id', 'name', 'email']) + ['role' => $user->roleValue()],
            'fields' => $this->fields($user), 'values' => $values,
            'readonly' => ['Табельный номер' => $user->profile?->tab_number, 'Колонна' => $user->profile?->column,
                'Инструктор' => $user->profile?->instructor, 'Дата увольнения' => $user->personnel?->fired_on?->format('Y-m-d')],
        ];
    }

    public function update(User $actor, array $input): array
    {
        abort_unless($actor->fresh()?->is_active, 403);
        $data = validator($input, $this->rules($actor))->validate();
        DB::transaction(function () use ($actor, $data) {
            $profileFields = Arr::only($data, ['birth_date', 'dateRoot', 'driverRoot', 'additional_notes']);
            $personFields = Arr::except($data, array_keys($profileFields));
            if ($actor->personnel()->exists() && $personFields !== []) {
                $this->directory->updateOwn($actor, $personFields);
            } else {
                if (array_key_exists('full_name', $personFields)) {
                    $actor->update(['name' => $personFields['full_name']]);
                }
                if (array_key_exists('phone_primary', $personFields)) {
                    $profileFields['phoneNumber'] = $personFields['phone_primary'];
                }
            }
            if ($profileFields !== []) {
                $actor->profile()->updateOrCreate(['user_id' => $actor->id], $profileFields);
            }
        });
        ClickHouseService::log('account.profile_updated', $actor->id, ['fields' => array_keys($data)], $actor);

        return $this->payload($actor->fresh());
    }
}
