<?php

namespace App\Services\Discipline;

use App\Enums\UserRole;
use App\Models\DisciplineAssignment;
use App\Models\DisciplineCard;
use App\Models\User;
use App\Services\ClickHouseService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DisciplineService
{
    public function __construct(private DisciplineAccess $access, private ReportScanner $scanner) {}

    /** Serialize an actor's retries, then lock the assignment for conflicting actors. */
    private function operation(User $actor, array $data, string $type, callable $action): array
    {
        Validator::make($data, ['operation_id' => 'required|uuid'])->validate();
        $fingerprint = hash('sha256', json_encode([$type, $data], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $data, $fingerprint, $action, $type) {
            $actor = User::whereKey($actor->id)->lock(DB::getDriverName() === 'pgsql' ? 'FOR NO KEY UPDATE' : true)->firstOrFail();
            abort_unless($actor->is_active, 403);
            if ($type === 'submit') {
                abort_unless($actor->isInstructor(), 403);
                $this->access->view($actor, $data['assignment_id']);
            } else {
                $this->access->manage($actor);
                if ($type === 'review') {
                    $this->access->view($actor, $data['assignment_id']);
                }
            }
            // Authorize before allowing replay; revocation must take effect immediately.
            $result = DB::table('discipline_operations')->where('user_id', $actor->id)->where('operation_id', $data['operation_id'])->first();
            if ($result) {
                abort_unless(hash_equals($result->fingerprint, $fingerprint), 409, 'Этот запрос уже использован с другим содержимым.');

                return json_decode($result->result, true, 512, JSON_THROW_ON_ERROR);
            }
            $result = $action($actor);
            DB::table('discipline_operations')->insert([
                'user_id' => $actor->id, 'operation_id' => $data['operation_id'], 'fingerprint' => $fingerprint,
                'result' => json_encode($result, JSON_THROW_ON_ERROR), 'created_at' => now(),
            ]);
            DB::afterCommit(function () use ($actor, $type, $result) {
                try {
                    ClickHouseService::log('discipline.'.$type, (int) ($result['assignment_id'] ?? $result['card_id']), $result, $actor);
                } catch (\Throwable $e) {
                    Log::warning('Discipline analytics unavailable', ['event' => $type]);
                }
            });

            return $result;
        });
    }

    public function create(User $actor, array $data): array
    {
        $this->access->manage($actor);
        $data['title'] = is_string($data['title'] ?? null) ? trim($data['title']) : ($data['title'] ?? null);
        $data['instructions'] = is_string($data['instructions'] ?? null) ? trim($data['instructions']) : ($data['instructions'] ?? null);
        $data = Validator::make($data, [
            'operation_id' => 'required|uuid', 'title' => 'required|string|max:255',
            'instructions' => 'required|string|max:20000', 'due_date' => 'required|date_format:Y-m-d',
            'all_instructors' => 'required|boolean', 'assignee_ids' => 'present|array|max:1000',
            'assignee_ids.*' => 'required|integer|distinct', 'require_response' => 'required|boolean',
            'require_report_file' => 'required|boolean', 'require_explanation_file' => 'required|boolean',
        ])->validate();

        return $this->operation($actor, $data, 'create', function (User $actor) use ($data) {
            $this->access->manage($actor);
            $query = User::where('role', UserRole::INSTRUCTOR->value)->where('is_active', true);
            if (! $data['all_instructors']) {
                $query->whereIn('id', $data['assignee_ids']);
            }
            $instructors = $query->with('profile')->orderBy('id')->get();
            if ($instructors->isEmpty() || (! $data['all_instructors'] && $instructors->count() !== count($data['assignee_ids']))) {
                throw ValidationException::withMessages(['assignee_ids' => 'Выберите действующих инструкторов.']);
            }
            $card = DisciplineCard::create([
                'created_by' => $actor->id, 'title' => trim($data['title']), 'instructions' => trim($data['instructions']),
                'due_date' => $data['due_date'], 'require_response' => $data['require_response'],
                'require_report_file' => $data['require_report_file'], 'require_explanation_file' => $data['require_explanation_file'],
            ]);
            foreach ($instructors as $instructor) {
                $assignment = $card->assignments()->create(['assignee_id' => $instructor->id, 'column_snapshot' => $instructor->profile?->column]);
                $this->notify($assignment, [$instructor->id], 'assigned');
            }

            return ['card_id' => $card->id];
        });
    }

    /** File descriptors are fingerprinted before scanning/storage, so exact retries don't duplicate files. */
    public function submit(User $actor, int $assignmentId, array $data, array $uploads): array
    {
        $current = $this->access->current($actor);
        abort_unless($current->isInstructor(), 403);
        $assignment = $this->access->view($current, $assignmentId);
        $data = Validator::make($data, [
            'operation_id' => 'required|uuid', 'version' => 'required|integer|min:1', 'response' => 'nullable|string|max:20000',
        ])->validate();
        $data['response'] = trim($data['response'] ?? '');
        $descriptors = [];
        $total = 0;
        foreach ($uploads as $category => $files) {
            abort_unless(in_array($category, ['report', 'explanation', 'other'], true), 422);
            foreach ($files as $file) {
                if (! $file instanceof UploadedFile || ! $file->isValid() || $file->getSize() <= 0 || $file->getSize() > 10 * 1024 * 1024) {
                    throw ValidationException::withMessages(['files' => 'Допустимы файлы размером от 1 байта до 10 МиБ.']);
                }
                $mime = $file->getMimeType();
                $extension = strtolower($file->getClientOriginalExtension());
                $types = ['application/pdf' => ['pdf'], 'image/jpeg' => ['jpg', 'jpeg'], 'image/png' => ['png']];
                if (! in_array($extension, $types[$mime] ?? [], true)) {
                    throw ValidationException::withMessages(['files' => 'Допустимы только PDF, JPEG и PNG с соответствующим расширением.']);
                }
                $name = basename(str_replace('\\', '/', $file->getClientOriginalName()));
                if (mb_strlen($name) > 255 || preg_match('/[\x00-\x1f\x7f]/', $name)) {
                    throw ValidationException::withMessages(['files' => 'Недопустимое имя файла.']);
                }
                $descriptors[] = ['category' => $category, 'name' => $name, 'mime' => $mime, 'size' => $file->getSize(), 'sha256' => hash_file('sha256', $file->getRealPath())];
                $total += $file->getSize();
            }
        }
        if (count($descriptors) > 5 || $total > 25 * 1024 * 1024) {
            throw ValidationException::withMessages(['files' => 'Не более пяти файлов и 25 МиБ суммарно.']);
        }
        $data['assignment_id'] = $assignmentId;
        $data['files'] = $descriptors;

        return $this->operation($actor, $data, 'submit', function (User $actor) use ($assignment, $data, $uploads, $descriptors) {
            abort_unless($actor->isInstructor(), 403);
            $assignment = DisciplineAssignment::with('card')->whereKey($assignment->id)->lockForUpdate()->firstOrFail();
            abort_unless($assignment->assignee_id === $actor->id, 403);
            $this->version($assignment, $data['version']);
            abort_unless(in_array($assignment->status, ['assigned', 'needs_revision'], true), 409, 'Отчёт уже отправлен.');
            $card = $assignment->card;
            $categories = array_column($descriptors, 'category');
            if (($card->require_response && $data['response'] === '') ||
                ($card->require_report_file && ! in_array('report', $categories, true)) ||
                ($card->require_explanation_file && ! in_array('explanation', $categories, true))) {
                throw ValidationException::withMessages(['response' => 'Выполните требования к ответу и обязательным приложениям.']);
            }
            foreach ($uploads as $files) {
                foreach ($files as $file) {
                    $this->scanner->scan($file);
                }
            }
            $report = $assignment->reports()->create([
                'attempt' => $assignment->reports()->count() + 1, 'response' => $data['response'],
                'requirements' => $card->only(['require_response', 'require_report_file', 'require_explanation_file']),
                'submitted_by' => $actor->id, 'submitted_at' => now(),
            ]);
            $i = 0;
            foreach ($uploads as $files) {
                foreach ($files as $file) {
                    $descriptor = $descriptors[$i++];
                    $path = 'discipline/'.Str::uuid();
                    // Retain objects on an ambiguous commit; never delete potentially referenced history.
                    $stream = fopen($file->getRealPath(), 'rb');
                    try {
                        abort_unless(Storage::disk('s3')->put($path, $stream, ['visibility' => 'private']), 503, 'Хранилище недоступно.');
                    } finally {
                        if (is_resource($stream)) {
                            fclose($stream);
                        }
                    }
                    $report->files()->create($descriptor + ['path' => $path, 'created_at' => now()]);
                }
            }
            $assignment->update(['status' => 'in_review', 'version' => $assignment->version + 1]);
            $receivers = User::where('role', UserRole::NACHALNIK->value)->where('is_active', true)->pluck('id')->all();
            $receivers[] = $card->created_by;
            $this->notify($assignment, $receivers, 'submitted');

            return ['assignment_id' => $assignment->id, 'report_id' => $report->id];
        });
    }

    public function review(User $actor, int $assignmentId, array $data): array
    {
        $this->access->manage($actor);
        $this->access->view($actor, $assignmentId);
        $data = Validator::make($data, [
            'operation_id' => 'required|uuid', 'version' => 'required|integer|min:1', 'report_id' => 'required|integer|min:1',
            'decision' => 'required|in:accepted,returned', 'comment' => 'nullable|string|max:20000',
        ])->validate();
        $data['comment'] = trim($data['comment'] ?? '');
        if ($data['decision'] === 'returned' && $data['comment'] === '') {
            throw ValidationException::withMessages(['comment' => 'Укажите причину возврата.']);
        }
        $data['assignment_id'] = $assignmentId;

        return $this->operation($actor, $data, 'review', function (User $actor) use ($assignmentId, $data) {
            $this->access->manage($actor);
            $this->access->view($actor, $assignmentId);
            $assignment = DisciplineAssignment::whereKey($assignmentId)->lockForUpdate()->firstOrFail();
            $this->version($assignment, $data['version']);
            abort_unless($assignment->status === 'in_review', 409, 'Нет отчёта, ожидающего проверки.');
            $report = $assignment->reports()->orderByDesc('attempt')->firstOrFail();
            abort_unless($report->id === (int) $data['report_id'] && $report->decision === null, 409, 'Выбран устаревший отчёт.');
            $report->update(['decision' => $data['decision'], 'review_comment' => $data['comment'], 'reviewed_by' => $actor->id, 'reviewed_at' => now()]);
            $assignment->update([
                'status' => $data['decision'] === 'accepted' ? 'accepted' : 'needs_revision',
                'accepted_at' => $data['decision'] === 'accepted' ? now() : null, 'version' => $assignment->version + 1,
            ]);
            $this->notify($assignment, [$assignment->assignee_id], $data['decision']);

            return ['assignment_id' => $assignment->id, 'report_id' => $report->id];
        });
    }

    private function version(DisciplineAssignment $assignment, int $version): void
    {
        abort_unless($assignment->version === $version, 412, 'Карточка изменилась. Обновите страницу и проверьте её состояние.');
    }

    private function notify(DisciplineAssignment $assignment, array $ids, string $event): void
    {
        foreach (array_unique($ids) as $id) {
            DB::table('discipline_notifications')->insert([
                'user_id' => $id, 'assignment_id' => $assignment->id, 'event' => $event, 'created_at' => now(),
            ]);
        }
    }
}
