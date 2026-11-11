<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\FormularTask;
use App\Models\User;
use App\Models\UserFormularLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class FormularService
{
    public function member(User $actor): User
    {
        $actor = $actor->fresh();
        abort_unless($actor && $actor->is_active && ($actor->isDriver() || $actor->isInstructor()), 403);

        return $actor;
    }

    public function instructor(User $actor): User
    {
        $actor = $this->member($actor);
        abort_unless($actor->isInstructor(), 403);
        abort_if(trim((string) $actor->profile?->column) === '', 422, 'В профиле не указана колонна. Обратитесь к администратору.');

        return $actor;
    }

    public function own(User $actor): array
    {
        $actor = $this->member($actor);
        $logs = UserFormularLog::where('user_id', $actor->id)->get()->keyBy('formular_task_id');

        return ['tasks' => FormularTask::where('is_active', true)->orderBy('sort_order')->orderBy('id')->get()->map(fn ($task) => [
            'id' => $task->id, 'title' => $task->title, 'description' => $task->description,
            'log' => ($log = $logs->get($task->id)) ? $this->payload($log) : null,
        ])->all()];
    }

    public function review(User $actor, array $input): array
    {
        $actor = $this->instructor($actor);
        $data = Validator::make($input, [
            'user_id' => 'nullable|integer|min:1', 'task_id' => 'nullable|integer|min:1',
            'status' => ['nullable', Rule::in(['not_submitted', 'pending', 'confirmed', 'returned'])],
            'page' => 'sometimes|integer|min:1',
        ])->validate();
        $column = $actor->profile->column;
        // A bounded matrix also includes employees who have not submitted a record.
        $query = DB::table('users as u')->join('user_profiles as p', 'p.user_id', '=', 'u.id')
            ->crossJoin('formular_tasks as t')
            ->leftJoin('user_formular_logs as l', fn ($join) => $join->on('l.user_id', '=', 'u.id')->on('l.formular_task_id', '=', 't.id'))
            ->where('u.role', UserRole::DRIVER->value)->where('u.is_active', true)
            ->where('p.column', $column)->where('t.is_active', true);
        if ($data['user_id'] ?? null) {
            $query->where('u.id', $data['user_id']);
        }
        if ($data['task_id'] ?? null) {
            $query->where('t.id', $data['task_id']);
        }
        $stats = ['total' => (clone $query)->count(), 'not_submitted' => (clone $query)->whereNull('l.id')->count()];
        foreach (['pending', 'confirmed', 'returned'] as $status) {
            $stats[$status] = (clone $query)->where('l.status', $status)->count();
        }
        if (($data['status'] ?? null) === 'not_submitted') {
            $query->whereNull('l.id');
        } elseif ($data['status'] ?? null) {
            $query->where('l.status', $data['status']);
        }
        $page = $query->select(['u.id as user_id', 'u.name', 't.id as task_id', 't.title', 'l.id', 'l.entry_text', 'l.status', 'l.version', 'l.completed_at', 'l.reviewed_at', 'l.reviewed_by', 'l.review_comment'])
            ->orderBy('u.name')->orderBy('u.id')->orderBy('t.sort_order')->orderBy('t.id')->paginate(25);

        return ['column' => $column, 'stats' => $stats, 'records' => $page->items(),
            'pagination' => ['page' => $page->currentPage(), 'next_page' => $page->hasMorePages() ? $page->currentPage() + 1 : null, 'total' => $page->total()],
            'crew' => User::where('role', UserRole::DRIVER)->where('is_active', true)->whereHas('profile', fn ($q) => $q->where('column', $column))->orderBy('name')->get(['id', 'name'])->all(),
            'tasks' => FormularTask::where('is_active', true)->orderBy('sort_order')->orderBy('id')->get(['id', 'title'])->all()];
    }

    public function mutate(User $actor, string $action, int $id, array $input): array
    {
        abort_unless(in_array($action, ['submit', 'confirmed', 'returned'], true), 404);
        $data = Validator::make($input, [
            'operation_id' => 'required|uuid', 'version' => 'required|integer|min:0',
            'entry_text' => [$action === 'submit' ? 'required' : 'prohibited', 'string', 'max:10000'],
            'review_comment' => [$action === 'returned' ? 'required' : ($action === 'submit' ? 'prohibited' : 'nullable'), 'string', 'max:2000'],
        ])->validate();
        if ($action === 'submit') {
            abort_if(trim($data['entry_text']) === '', 422, 'Введите текст записи.');
        }
        if ($action === 'returned') {
            abort_if(trim($data['review_comment']) === '', 422, 'Укажите замечание.');
        }
        $operation = strtolower($data['operation_id']);
        $fingerprint = hash('sha256', json_encode([$action, $id, (int) $data['version'], $data['entry_text'] ?? null, $data['review_comment'] ?? null], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $action, $id, $data, $operation, $fingerprint) {
            $user = User::whereKey($actor->id)->lock(DB::getDriverName() === 'pgsql' ? 'for no key update' : true)->firstOrFail();
            $user = $action === 'submit' ? $this->member($user) : $this->instructor($user);
            if ($action === 'submit') {
                FormularTask::where('is_active', true)->findOrFail($id);
                $log = UserFormularLog::where('user_id', $user->id)->where('formular_task_id', $id)->lockForUpdate()->first();
            } else {
                $log = UserFormularLog::whereHas('user', fn ($q) => $q->where('is_active', true)->where('role', UserRole::DRIVER)->whereHas('profile', fn ($p) => $p->where('column', $user->profile->column)))
                    ->whereHas('task', fn ($q) => $q->where('is_active', true))->lockForUpdate()->findOrFail($id);
                abort_if($log->user_id === $user->id, 403);
            }
            $receipt = DB::table('formular_operations')->where('user_id', $user->id)->where('operation_id', $operation)->first();
            if ($receipt) {
                abort_unless(hash_equals($receipt->fingerprint, $fingerprint), 409, 'Этот ключ операции уже использован.');

                return json_decode($receipt->result, true, 512, JSON_THROW_ON_ERROR);
            }
            abort_unless((int) ($log?->version ?? 0) === (int) $data['version'], 409, 'Запись изменилась. Обновите данные перед отправкой.');
            if ($action === 'submit') {
                abort_if($log && $log->status !== 'returned', 409, 'Запись уже отправлена. Изменение возможно после возврата инструктором.');
                if (! $log) {
                    $log = new UserFormularLog(['user_id' => $user->id, 'formular_task_id' => $id]);
                    $log->version = 0;
                }
                $log->entry_text = trim($data['entry_text']);
                $log->completed_at = now();
                $log->status = 'pending';
                $log->reviewed_by = null;
                $log->reviewed_at = null;
                $log->review_comment = null;
            } else {
                abort_unless($log->status === 'pending', 409, 'Эта запись уже проверена.');
                $log->status = $action;
                $log->reviewed_by = $user->id;
                $log->reviewed_at = now();
                $log->review_comment = $data['review_comment'] ?? null;
            }
            $log->version++;
            $log->save();
            $result = ['log' => $this->payload($log)];
            DB::table('formular_operations')->insert(['user_id' => $user->id, 'operation_id' => $operation, 'fingerprint' => $fingerprint, 'result' => json_encode($result, JSON_THROW_ON_ERROR), 'created_at' => now()]);
            DB::afterCommit(function () use ($action, $log, $user) {
                try {
                    ClickHouseService::log($action === 'submit' ? 'formular_logged' : 'formular.review_'.$action, $action === 'submit' ? $log->formular_task_id : $log->id,
                        ['log_id' => $log->id, 'user_id' => $log->user_id, 'reviewer_id' => $action === 'submit' ? null : $user->id], $user);
                } catch (\Throwable $e) {
                    report($e);
                }
            });

            return $result;
        }, 3);
    }

    public function payload(UserFormularLog $log): array
    {
        return $log->only(['id', 'user_id', 'formular_task_id', 'entry_text', 'completed_at', 'status', 'version', 'reviewed_by', 'reviewed_at', 'review_comment']);
    }
}
