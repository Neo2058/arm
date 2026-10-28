<?php

namespace App\Services\Payroll;

use App\Models\User;
use App\Models\WorkShift;
use App\Services\ClickHouseService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Entry point for the upcoming web/mobile forms. No new public routes in this block. */
class PersonalWorkShiftService
{
    public function preview(User $actor, array $input): WorkShift
    {
        abort_unless($actor->fresh()?->is_active, 403);
        $data = $this->validated($input);
        $data['break_duration'] = $data['break_duration'] ?? 0;
        $shift = new WorkShift($data + ['user_id' => $actor->id, 'type' => 'work', 'calculation_version' => 2]);
        $snapshot = app(PersonalWorkTimeSettings::class)->snapshot($actor->id, $data['shift_date']);
        $result = app(PersonalWorkTimeCalculator::class)->calculate($shift, $snapshot);
        $shift->calculation_snapshot = $snapshot;
        $shift->calculation_result = $result;
        $shift->total_minutes = $result['paid_minutes'];
        $shift->estimated_earnings = $result['subtotal_kopecks'] / 100;

        return $shift;
    }

    public function create(User $actor, array $input, string $operationId): WorkShift
    {
        Validator::make(['operation_id' => $operationId], ['operation_id' => 'required|uuid'])->validate();
        $data = $this->validated($input);
        $hash = hash('sha256', json_encode($this->canonical($data), JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $data, $operationId, $hash) {
            // Serializes overlapping inserts for one user in PostgreSQL, including different UUIDs.
            $user = User::whereKey($actor->id)->lockForUpdate()->first();
            abort_unless($user?->is_active, 403);
            $existing = WorkShift::where('user_id', $user->id)->where('operation_id', $operationId)->first();
            if ($existing) {
                abort_unless(hash_equals($existing->operation_hash, $hash), 409, 'UUID уже использован для других данных.');

                return $existing;
            }
            $shift = $this->preview($user, $data);
            // Includes legacy records: the same worked time cannot be counted twice.
            $this->assertNoOverlap($shift);
            $shift->operation_id = $operationId;
            $shift->operation_hash = $hash;
            $shift->save();
            DB::afterCommit(fn () => ClickHouseService::log('work_shift.saved', $shift->id, [
                'shift_date' => $shift->shift_date->toDateString(), 'type' => $shift->type, 'calculation_version' => 2,
            ], $user));

            return $shift;
        });
    }

    public function previewUpdate(User $actor, int $id, array $input, int $expectedRevision, bool $refreshSnapshot = false): WorkShift
    {
        abort_unless($actor->fresh()?->is_active, 403);
        $shift = WorkShift::where('user_id', $actor->id)->where('calculation_version', 2)->findOrFail($id);
        abort_unless($shift->revision === $expectedRevision, 409, 'Смена уже изменена. Загрузите актуальную запись.');

        return $this->prepareUpdate($shift, $input, $refreshSnapshot);
    }

    public function update(User $actor, int $id, array $input, int $expectedRevision, bool $refreshSnapshot = false): WorkShift
    {
        return DB::transaction(function () use ($actor, $id, $input, $expectedRevision, $refreshSnapshot) {
            $user = User::whereKey($actor->id)->lockForUpdate()->first();
            abort_unless($user?->is_active, 403);
            $shift = WorkShift::where('user_id', $user->id)->where('calculation_version', 2)->lockForUpdate()->findOrFail($id);
            abort_unless($shift->revision === $expectedRevision, 409, 'Смена уже изменена. Загрузите актуальную запись.');
            $shift = $this->prepareUpdate($shift, $input, $refreshSnapshot);
            $this->assertNoOverlap($shift);
            $shift->revision++;
            $shift->useCalculationSnapshot($shift->calculation_snapshot);
            $shift->save();
            DB::afterCommit(fn () => ClickHouseService::log('work_shift.saved', $shift->id, ['shift_date' => $shift->shift_date->toDateString(), 'type' => $shift->type, 'calculation_version' => 2], $user));

            return $shift;
        });
    }

    private function prepareUpdate(WorkShift $shift, array $input, bool $refreshSnapshot): WorkShift
    {
        $data = $this->validated($input);
        if (($data['type'] ?? 'work') !== $shift->type) {
            throw ValidationException::withMessages(['type' => 'Тип сохранённой записи менять нельзя.']);
        }
        $data['break_duration'] = $data['break_duration'] ?? 0;
        $shift->fill($data + ['break_duration' => 0, 'segments' => null, 'shift_code' => null, 'route_id' => null, 'start_location' => null, 'end_location' => null]);
        $snapshot = $refreshSnapshot ? app(PersonalWorkTimeSettings::class)->snapshot($shift->user_id, $data['shift_date']) : $shift->getOriginal('calculation_snapshot');
        $result = app(PersonalWorkTimeCalculator::class)->calculate($shift, $snapshot);
        $shift->calculation_snapshot = $snapshot;
        $shift->calculation_result = $result;
        $shift->total_minutes = $result['paid_minutes'];
        $shift->estimated_earnings = $result['subtotal_kopecks'] / 100;

        return $shift;
    }

    public function validated(array $input): array
    {
        Validator::make($input, ['type' => 'sometimes|in:work,deviation'])->validate();
        if (($input['type'] ?? 'work') === 'deviation') {
            return app(PersonalWorkAbsenceCalculator::class)->input($input);
        }

        return Validator::make($input, [
            'shift_date' => 'required|date_format:Y-m-d',
            'started_at' => ['required', 'date', 'regex:/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(?::00)?$/'],
            'ended_at' => ['required', 'date', 'regex:/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(?::00)?$/'],
            'break_duration' => 'nullable|integer|min:0',
            'shift_code' => 'nullable|string|max:8',
            'route_id' => 'nullable|integer|exists:routes_catalog,id',
            'start_location' => 'nullable|string|max:255',
            'end_location' => 'nullable|string|max:255',
            'segments' => 'nullable|array|max:128',
            'segments.*' => 'array:kind,started_at,ended_at',
            'segments.*.kind' => 'required|in:line,reserve,surface',
            'segments.*.started_at' => ['required', 'date', 'regex:/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(?::00)?$/'],
            'segments.*.ended_at' => ['required', 'date', 'regex:/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(?::00)?$/'],
        ])->validate();
    }

    private function assertNoOverlap(WorkShift $shift): void
    {
        $start = $shift->getAttributes()['started_at'];
        $end = $shift->getAttributes()['ended_at'];
        $lastDate = CarbonImmutable::parse($end, 'Europe/Moscow')->subSecond()->toDateString();
        $overlap = WorkShift::where('user_id', $shift->user_id)
            ->when($shift->exists, fn ($q) => $q->whereKeyNot($shift->id))
            ->where(fn ($q) => $q->where(fn ($times) => $times->where('started_at', '<', $end)->where('ended_at', '>', $start))
                ->orWhere(fn ($unknown) => $unknown->where(fn ($nulls) => $nulls->whereNull('started_at')->orWhereNull('ended_at'))
                    ->whereBetween('shift_date', [substr($start, 0, 10), $lastDate])))->exists();
        if ($overlap) {
            throw ValidationException::withMessages(['started_at' => 'Период пересекается с сохранённой сменой или отвлечением.']);
        }
    }

    private function canonical(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = $this->canonical($item);
            }
        }

        return $value;
    }
}
