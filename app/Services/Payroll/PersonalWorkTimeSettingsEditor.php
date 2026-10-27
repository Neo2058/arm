<?php

namespace App\Services\Payroll;

use App\Models\User;
use App\Models\WorkTimeMonthSettings;
use App\Services\ClickHouseService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PersonalWorkTimeSettingsEditor
{
    public const KEYS = ['line_rate_kopecks', 'reserve_rate_kopecks', 'surface_rate_kopecks', 'evening_percent', 'night_percent', 'rest_dates'];

    public function show(User $actor, string $month): array
    {
        Validator::make(['month' => $month], ['month' => 'required|date_format:Y-m'])->validate();

        return DB::transaction(function () use ($actor, $month) {
            $user = User::whereKey($actor->id)->lockForUpdate()->first();
            abort_unless($user?->is_active, 403);
            $row = $this->row($user, $month);
            $snapshot = app(PersonalWorkTimeSettings::class)->snapshot($user->id, $month.'-01');
            $settings = array_intersect_key($snapshot, array_flip(self::KEYS));
            $settings['rest_dates'] = $row?->settings['rest_dates'] ?? [];

            return ['month' => $month, 'revision' => $row?->revision ?? 0, 'settings' => $settings, 'effective_rules' => $snapshot];
        });
    }

    public function validateInput(string $month, array $input): array
    {
        Validator::make(['month' => $month], ['month' => 'required|date_format:Y-m'])->validate();
        $data = Validator::make($input, [
            'settings' => 'required|array:'.implode(',', self::KEYS).'|min:1',
            'settings.line_rate_kopecks' => 'sometimes|integer|min:0|max:10000000',
            'settings.reserve_rate_kopecks' => 'sometimes|integer|min:0|max:10000000',
            'settings.surface_rate_kopecks' => 'sometimes|integer|min:0|max:10000000',
            'settings.evening_percent' => 'sometimes|integer|min:0|max:100',
            'settings.night_percent' => 'sometimes|integer|min:0|max:100',
            'settings.rest_dates' => 'sometimes|array|max:31',
            'settings.rest_dates.*' => 'required|date_format:Y-m-d|distinct',
        ])->validate()['settings'];
        foreach ($data['rest_dates'] ?? [] as $date) {
            if (substr($date, 0, 7) !== $month) {
                throw ValidationException::withMessages(['settings.rest_dates' => 'Выходные должны относиться к выбранному месяцу.']);
            }
        }

        foreach ($data as $key => &$value) {
            if ($key !== 'rest_dates') {
                $value = (int) $value;
            }
        }
        unset($value);
        if (isset($data['rest_dates'])) {
            sort($data['rest_dates']);
        }

        return $data;
    }

    public function update(User $actor, string $month, array $settings, int $expectedRevision): array
    {
        $settings = $this->validateInput($month, ['settings' => $settings]);

        return DB::transaction(function () use ($actor, $month, $settings, $expectedRevision) {
            $user = User::whereKey($actor->id)->lockForUpdate()->first();
            abort_unless($user?->is_active, 403);
            $row = $this->row($user, $month);
            abort_unless(($row?->revision ?? 0) === $expectedRevision, 409, 'Настройки уже изменены. Загрузите актуальную редакцию.');
            $old = $row?->settings ?? [];
            $changed = array_keys(array_filter($settings, fn ($value, $key) => ! array_key_exists($key, $old) || $old[$key] !== $value, ARRAY_FILTER_USE_BOTH));
            if ($changed) {
                $row ??= new WorkTimeMonthSettings(['user_id' => $user->id, 'month' => $month.'-01']);
                $row->settings = array_replace($old, $settings);
                $row->revision = $row->exists ? $row->revision + 1 : 1;
                $row->save();
                DB::afterCommit(fn () => ClickHouseService::log('work_time.settings_saved', $user->id, ['user_id' => $user->id, 'month' => $month, 'changed_fields' => $changed], $user));
            }

            return $this->show($user, $month);
        });
    }

    private function row(User $user, string $month): ?WorkTimeMonthSettings
    {
        return WorkTimeMonthSettings::where('user_id', $user->id)->whereDate('month', $month.'-01')->first();
    }
}
