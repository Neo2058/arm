<?php

namespace App\Services\Journal;

use App\Models\JournalTask;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\ClickHouseService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class JournalTasks
{
    public static function payload(JournalTask $task): array
    {
        return ['id' => (string) $task->id, 'version' => (string) $task->version, 'title' => $task->title,
            'description' => $task->description, 'due_date' => $task->due_date?->format('Y-m-d'),
            'status' => $task->status, 'source' => $task->source,
            'is_overdue' => $task->status === 'pending' && $task->due_date && $task->due_date->lt(today())];
    }

    public function mutate(User $actor, string $action, ?int $id, array $input, bool $mobile = true): array
    {
        abort_unless(in_array($action, ['create', 'update', 'complete', 'delete'], true), 404);
        $data = Validator::make($input, [
            'operation_id' => [$mobile ? 'required' : 'nullable', 'uuid'],
            'version' => [($mobile && $action !== 'create') ? 'required' : 'nullable', 'integer', 'min:1'],
            'title' => [in_array($action, ['create', 'update']) ? 'required' : 'nullable', 'string', 'max:255'],
            'description' => 'nullable|string|max:20000', 'due_date' => 'nullable|date|date_format:Y-m-d',
            'source' => 'nullable|string|max:100',
        ])->validate();
        $operation = strtolower($data['operation_id'] ?? (string) Str::uuid());
        $fingerprint = hash('sha256', json_encode([$action, $id,
            isset($data['version']) ? (string) $data['version'] : null,
            $data['title'] ?? null, [array_key_exists('description', $data), $data['description'] ?? null], [array_key_exists('due_date', $data), $data['due_date'] ?? null], $data['source'] ?? null], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $action, $id, $data, $operation, $fingerprint, $mobile) {
            $user = User::whereKey($actor->id)->lock(DB::getDriverName() === 'pgsql' ? 'for no key update' : true)->firstOrFail();
            abort_unless($user->is_active && $user->isInstructor(), 403);
            $column = UserProfile::where('user_id', $user->id)->lockForUpdate()->value('column');
            abort_if($mobile && ! $column, 422, 'Укажите колонну через администратора.');
            $receipt = DB::table('journal_task_operations')->where('user_id', $user->id)->where('operation_id', $operation)->first();
            if ($receipt) {
                abort_unless($receipt->column === $column, 404);
                abort_unless(hash_equals($receipt->fingerprint, $fingerprint), 409, 'Ключ операции уже использован для другого запроса.');

                return json_decode($receipt->result, true, 512, JSON_THROW_ON_ERROR);
            }
            $task = $action === 'create' ? new JournalTask(['user_id' => $user->id, 'column' => $column, 'status' => 'pending', 'source' => $mobile ? 'manual' : ($data['source'] ?? 'manual'), 'version' => 1]) : JournalTask::where('user_id', $user->id)->where('column', $column)->lockForUpdate()->findOrFail($id);
            if ($action !== 'create' && isset($data['version']) && (string) $task->version !== (string) $data['version']) {
                abort(response()->json(['message' => 'Задача изменена. Обновите её перед повторной отправкой.', 'current' => self::payload($task)], 412));
            }
            $changed = true;
            if (in_array($action, ['create', 'update'])) {
                $task->title = $data['title'];
                if ($action === 'create' || array_key_exists('description', $data)) {
                    $task->description = $data['description'] ?? null;
                }
                if ($action === 'create' || array_key_exists('due_date', $data)) {
                    $task->due_date = $data['due_date'] ?? null;
                }
            } elseif ($action === 'complete') {
                $changed = $task->status !== 'done';
                $task->status = 'done';
            }
            if ($action !== 'create' && $changed) {
                $task->version++;
            }
            if ($action === 'delete') {
                $result = ['deleted' => true, 'id' => (string) $task->id];
                $task->delete();
            } else {
                if ($changed) {
                    $task->save();
                }
                $result = ['task' => self::payload($task)];
            }
            DB::table('journal_task_operations')->insert(['user_id' => $user->id, 'operation_id' => $operation, 'column' => $column, 'fingerprint' => $fingerprint, 'result' => json_encode($result, JSON_THROW_ON_ERROR), 'created_at' => now()]);
            if ($changed) {
                $event = match ($action) {
                    'create' => 'journal.todo.created','update' => 'journal.todo.updated','delete' => 'journal.todo.deleted','complete' => 'normative_completed'
                };
                $details = $action === 'complete' ? ['type' => $task->source, 'title' => $task->title, 'column' => $task->column] : ['title' => $task->title, 'source' => $task->source];
                DB::afterCommit(function () use ($event, $task, $details, $user, $action) {
                    try {
                        ClickHouseService::log($event, $action === 'complete' ? $user->id : $task->id, $details, $user);
                    } catch (\Throwable $e) {
                        report($e);
                    }
                });
            }

            return $result;
        }, 3);
    }
}
