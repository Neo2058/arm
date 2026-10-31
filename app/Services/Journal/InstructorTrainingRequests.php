<?php

namespace App\Services\Journal;

use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class InstructorTrainingRequests
{
    public function column(User $actor): string
    {
        abort_unless($actor->is_active && $actor->isInstructor(), 403);
        $column = UserProfile::where('user_id', $actor->id)->value('column');
        if ($column === null || trim($column) === '') {
            throw ValidationException::withMessages(['column' => 'Укажите колонну через администратора.']);
        }

        return $column;
    }

    public function crew(string $column)
    {
        return User::where('role', 'driver')->where('is_active', true)->whereHas('profile', fn ($q) => $q->where('column', $column))->with('profile')->orderBy('name')->orderBy('id');
    }

    public function mutate(User $actor, string $action, ?int $id, array $input): array
    {
        abort_unless(in_array($action, ['create', 'update', 'delete'], true), 404);
        $rules = ['operation_id' => 'required|uuid'];
        if ($action !== 'create') {
            $rules['version'] = 'required|integer|min:1';
        }
        if ($action !== 'delete') {
            $rules += ['planning_month' => 'required|date_format:Y-m', 'shift_type' => ['required', 'string', 'max:32', 'regex:/^[\p{L}\p{N} +.\/-]+$/u'], 'participant_count' => 'required|integer|min:1', 'participants' => 'required|array|min:1', 'participants.*' => 'required|integer|min:1|distinct'];
        }
        $data = Validator::make($input, $rules)->validate();
        $data['operation_id'] = strtolower($data['operation_id']);
        if (isset($data['version'])) {
            $data['version'] = (int) $data['version'];
        }
        if (isset($data['participant_count'])) {
            $data['participant_count'] = (int) $data['participant_count'];
        }
        if ($action !== 'delete') {
            $data['shift_type'] = trim($data['shift_type']);
            if ($data['shift_type'] === '') {
                throw ValidationException::withMessages(['shift_type' => 'Укажите тип смены.']);
            }
            $data['participants'] = array_map('intval', $data['participants']);
            sort($data['participants']);
            if (count($data['participants']) !== (int) $data['participant_count']) {
                throw ValidationException::withMessages(['participants' => 'Количество выбранных машинистов должно совпадать с количеством участников.']);
            }
        }
        $fingerprint = hash('sha256', json_encode([$action, $id, $data], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $action, $id, $data, $fingerprint) {
            $actor = User::whereKey($actor->id)->lock(DB::getDriverName() === 'pgsql' ? 'for no key update' : true)->firstOrFail();
            $column = $this->column($actor);
            UserProfile::where('user_id', $actor->id)->lockForUpdate()->first();
            $column = $this->column($actor);
            $ops = DB::table('instructor_training_operations');
            $receipt = (clone $ops)->where('instructor_id', $actor->id)->where('operation_id', $data['operation_id'])->first();
            if ($receipt) {
                abort_unless($receipt->column === $column, 404);
                abort_unless(hash_equals($receipt->fingerprint, $fingerprint), 409, 'Ключ операции уже использован для другого запроса.');

                return json_decode($receipt->result, true, 512, JSON_THROW_ON_ERROR);
            }
            $query = DB::table('instructor_training_requests');
            $row = null;
            if ($action !== 'create') {
                $row = (clone $query)->where('instructor_id', $actor->id)->where('column', $column)->where('id', $id)->lockForUpdate()->first();
                abort_unless($row, 404);
                if (DB::table('instructor_training_completions')->where('request_id', $id)->exists()) {
                    throw ValidationException::withMessages(['completion' => 'Учтённое занятие нельзя изменить или удалить.']);
                }
                if ((int) $row->version !== (int) $data['version']) {
                    throw ValidationException::withMessages(['version' => 'Карточка изменена. Обновите страницу перед сохранением.']);
                }
            }
            if ($action === 'delete') {
                (clone $query)->where('id', $id)->delete();
                $result = ['deleted' => true, 'id' => (string) $id];
            } else {
                // Lock members and profiles in a stable order; membership is checked again under locks.
                User::whereIn('id', $data['participants'])->orderBy('id')->lock(DB::getDriverName() === 'pgsql' ? 'for share' : true)->get();
                UserProfile::whereIn('user_id', $data['participants'])->orderBy('user_id')->lockForUpdate()->get();
                if ($this->crew($column)->whereIn('id', $data['participants'])->count() !== count($data['participants'])) {
                    throw ValidationException::withMessages(['participants' => 'Выберите действующих машинистов только своей колонны.']);
                }
                $month = $data['planning_month'].'-01';
                // All months and columns share the owner lock: changing columns cannot bypass the monthly limit.
                $count = (clone $query)->where('instructor_id', $actor->id)->where('planning_month', $month)->when($id, fn ($q) => $q->where('id', '<>', $id))->count();
                if ($count >= 10) {
                    throw ValidationException::withMessages(['planning_month' => 'На этот месяц уже создано 10 карточек.']);
                }
                $values = ['planning_month' => $month, 'shift_type' => $data['shift_type'], 'participant_count' => (int) $data['participant_count'], 'updated_at' => now(), 'version' => $row ? $row->version + 1 : 1];
                if ($row) {
                    (clone $query)->where('id', $id)->update($values);
                } else {
                    $id = $query->insertGetId($values + ['instructor_id' => $actor->id, 'column' => $column, 'created_at' => now()]);
                }
                DB::table('instructor_training_members')->where('request_id', $id)->delete();
                DB::table('instructor_training_members')->insert(array_map(fn ($user) => ['request_id' => $id, 'user_id' => $user], $data['participants']));
                $result = ['id' => (string) $id, 'version' => (string) $values['version']];
            }
            $ops->insert(['instructor_id' => $actor->id, 'operation_id' => strtolower($data['operation_id']), 'column' => $column, 'fingerprint' => $fingerprint, 'result' => json_encode($result, JSON_THROW_ON_ERROR), 'created_at' => now()]);

            return $result;
        }, 3);
    }
}
