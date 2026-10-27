<?php

namespace App\Services\Arm;

use App\Models\ArmPersonnel;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PersonnelDirectory
{
    public function canAccess(?User $actor): bool
    {
        return $actor && $actor->is_active && ($actor->isAdmin() || $actor->isDispatcher()
            || $actor->isInstructor() || $actor->isOperator());
    }

    /** Shared scope for screens and other modules; never trust a client-supplied column. */
    public function query(User $actor): Builder
    {
        $query = ArmPersonnel::query()->with('user.profile');
        if (! $this->canAccess($actor)) {
            return $query->whereRaw('1 = 0');
        }
        if ($actor->isInstructor()) {
            $column = $actor->profile()->value('column');
            if ($column === null || trim($column) === '') {
                return $query->whereRaw('1 = 0');
            }
            $query->whereHas('user.profile', fn (Builder $q) => $q->where('column', $column));
        }

        return $query;
    }

    public function canEdit(User $actor): bool
    {
        return $this->canAccess($actor) && ! $actor->isOperator();
    }

    public function rules(User $actor): array
    {
        return [
            'full_name' => 'sometimes|required|string|max:255',
            'position_code' => 'sometimes|nullable|string|max:8',
            'class_code' => 'sometimes|nullable|string|max:4',
            'phone_primary' => 'sometimes|nullable|string|max:16',
            'phone_secondary' => 'sometimes|nullable|string|max:16',
            'brigade_code' => 'sometimes|nullable|string|max:8',
            'is_brigadier' => 'sometimes|boolean',
            'hired_on' => 'sometimes|nullable|date',
            'med_from' => 'sometimes|nullable|date',
            'med_to' => 'sometimes|nullable|date',
            'seniority_on' => 'sometimes|nullable|date',
            'assistant_seniority_on' => 'sometimes|nullable|date',
            'assistant_seniority_years' => 'sometimes|integer|min:0|max:999',
            'brigadier_from' => 'sometimes|nullable|date',
            'brigadier_to' => 'sometimes|nullable|date',
            'shop_code' => 'sometimes|nullable|string|max:8',
            'roster_number' => 'sometimes|nullable|string|max:8',
            'category_code' => 'sometimes|nullable|string|max:4',
            'premium_flag' => 'sometimes|nullable|string|max:4',
            'main_tab_number' => 'sometimes|nullable|string|max:8',
            'depo_code' => 'sometimes|nullable|string|max:8',
            'early_windows' => 'sometimes|array|max:3',
            'late_windows' => 'sometimes|array|max:3',
            'early_windows.*' => 'array:time,reason',
            'late_windows.*' => 'array:time,reason',
            'early_windows.*.time' => ['nullable', 'regex:/^(?:[01]?[0-9]|2[0-3])[.:][0-5][0-9]$/'],
            'late_windows.*.time' => ['nullable', 'regex:/^(?:[01]?[0-9]|2[0-3])[.:][0-5][0-9]$/'],
            'early_windows.*.reason' => 'nullable|string|max:8',
            'late_windows.*.reason' => 'nullable|string|max:8',
            'source_data' => 'missing',
            // Membership changes are administrative and cannot be used to move a card into view.
            'column' => $actor->isAdmin() ? 'sometimes|nullable|string|max:32' : 'missing',
            'tab_number' => 'missing',
            'user_id' => 'missing',
            'fired_on' => 'missing',
        ];
    }

    public function update(User $actor, int $id, array $data): ArmPersonnel
    {
        abort_unless($this->canEdit($actor), 403);
        $data = validator($data, $this->rules($actor))->validate();

        return $this->persist($actor, $id, $data, false);
    }

    public function updateOwn(User $actor, array $data): ArmPersonnel
    {
        abort_unless($actor->is_active, 403);
        $rules = array_replace($this->rules($actor), ['column' => 'missing']);
        $data = validator($data, $rules)->validate();
        $id = ArmPersonnel::where('user_id', $actor->id)->sole()->id;

        return $this->persist($actor, $id, $data, true);
    }

    private function persist(User $actor, int $id, array $data, bool $own): ArmPersonnel
    {
        return DB::transaction(function () use ($actor, $id, $data, $own) {
            // Serialize with column assignments and import changes on the same card.
            $query = $own ? ArmPersonnel::where('user_id', $actor->id) : $this->query($actor);
            $person = $query->lockForUpdate()->findOrFail($id);
            $user = User::query()->lockForUpdate()->findOrFail($person->user_id);
            abort_unless($actor->fresh()?->is_active, 403);
            if (! $own) {
                abort_unless($this->query($actor)->whereKey($id)->exists(), 404);
            }
            $profile = UserProfile::firstOrNew(['user_id' => $user->id]);
            if (array_key_exists('column', $data)) {
                $column = trim((string) $data['column']);
                $profile->column = $column === '' ? null : $column;
                unset($data['column']);
            }
            foreach (['early_windows', 'late_windows'] as $field) {
                if (array_key_exists($field, $data)) {
                    $data[$field] = collect($data[$field])->map(fn ($window) => [
                        'time' => str_replace(':', '.', trim($window['time'] ?? '')),
                        'reason' => trim($window['reason'] ?? ''),
                    ])->filter(fn ($window) => $window['time'] !== '' || $window['reason'] !== '')->values()->all();
                }
            }
            $person->fill($data);
            if ($person->isDirty(['med_from', 'med_to']) && $person->med_from && $person->med_to && $person->med_to->lt($person->med_from)) {
                throw ValidationException::withMessages(['med_to' => 'Дата окончания должна быть не раньше даты начала.']);
            }
            $nameChanged = $person->isDirty('full_name');
            $person->save();
            if ($nameChanged) {
                $user->update(['name' => rtrim($person->full_name, '+ ')]);
            }
            $profile->fill([
                'tab_number' => $person->tab_number,
                'phoneNumber' => $person->phone_primary,
                'position' => $person->position_code,
                'normative_class' => $person->class_code,
                'is_brigadir' => $person->is_brigadier,
                'is_pomoshnik' => str_contains($person->position_code ?? '', 'П'),
            ])->save();

            return $person->refresh();
        });
    }
}
