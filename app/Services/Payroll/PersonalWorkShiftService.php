<?php

namespace App\Services\Payroll;

use App\Models\User;
use App\Models\WorkShift;
use App\Models\WorkTimeMonthSettings;
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
        $this->assertNotOnRest($shift);

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
            $this->assertNotOnRest($shift);
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
            $this->assertNotOnRest($shift);
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
        $this->assertNotOnRest($shift);

        return $shift;
    }

    public function delete(User $actor, int $id, int $expectedRevision): array
    {
        return DB::transaction(function () use ($actor, $id, $expectedRevision) {
            $user = User::whereKey($actor->id)->lockForUpdate()->first();
            abort_unless($user?->is_active, 403);
            $shift = WorkShift::where('user_id', $user->id)->where('calculation_version', 2)->lockForUpdate()->findOrFail($id);
            abort_unless($shift->revision === $expectedRevision, 409, 'Смена уже изменена. Загрузите актуальную запись.');
            $view = [
                'id' => $shift->id,
                'revision' => $shift->revision,
                'shift_date' => $shift->shift_date->toDateString(),
                'type' => $shift->type,
                'absence_kind' => $shift->absence_kind,
                'absence_end' => $shift->absence_end?->toDateString(),
                'deleted' => true,
            ];
            $shift->delete();
            DB::afterCommit(fn () => ClickHouseService::log('work_shift.saved', $view['id'], [
                'shift_date' => $view['shift_date'], 'type' => $view['type'], 'calculation_version' => 2, 'deleted' => true,
            ], $user));

            return $view;
        });
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

    public function assertRestDatesFree(User $user, array $dates): void
    {
        $busy = $this->occupiedDatesAmong($user, $dates);
        if ($busy) {
            throw ValidationException::withMessages(['settings.rest_dates' => 'На день уже есть смена или отсутствие. Выходной назначить нельзя.']);
        }
    }

    private function assertNotOnRest(WorkShift $shift): void
    {
        if ($this->restDatesAmong((int) $shift->user_id, $this->calendarDates($shift))) {
            throw ValidationException::withMessages(['shift_date' => 'На день уже назначен выходной. Сначала снимите отметку выходного.']);
        }
    }

    private function restDatesAmong(int $userId, array $dates): array
    {
        $dates = array_values(array_unique(array_filter($dates)));
        if ($dates === []) {
            return [];
        }
        $wanted = array_fill_keys($dates, true);
        $months = array_unique(array_map(fn (string $date) => substr($date, 0, 7).'-01', $dates));
        $rows = WorkTimeMonthSettings::where('user_id', $userId)
            ->where(function ($query) use ($months) {
                foreach ($months as $month) {
                    $query->orWhereDate('month', $month);
                }
            })->get();
        $hit = [];
        foreach ($rows as $row) {
            foreach ($row->settings['rest_dates'] ?? [] as $date) {
                if (isset($wanted[$date])) {
                    $hit[$date] = true;
                }
            }
        }

        return array_keys($hit);
    }

    private function occupiedDatesAmong(User $user, array $dates, ?int $exceptId = null): array
    {
        $dates = array_values(array_unique(array_filter($dates)));
        if ($dates === []) {
            return [];
        }
        sort($dates);
        $from = $dates[0];
        $to = $dates[array_key_last($dates)];
        $toEnd = CarbonImmutable::parse($to, 'Europe/Moscow')->addDay()->format('Y-m-d H:i:s');
        $rows = WorkShift::where('user_id', $user->id)
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->where(fn ($q) => $q->where(fn ($times) => $times->whereNotNull('started_at')->whereNotNull('ended_at')
                ->where('started_at', '<', $toEnd)->where('ended_at', '>', $from.' 00:00:00'))
                ->orWhere(fn ($unknown) => $unknown->where(fn ($nulls) => $nulls->whereNull('started_at')->orWhereNull('ended_at'))
                    ->whereBetween('shift_date', [$from, $to])))
            ->get();
        $hit = [];
        foreach ($dates as $date) {
            foreach ($rows as $shift) {
                if ($this->occupiesDate($shift, $date)) {
                    $hit[] = $date;
                    break;
                }
            }
        }

        return $hit;
    }

    private function calendarDates(WorkShift $shift): array
    {
        $start = $shift->getAttributes()['started_at'] ?? null;
        $end = $shift->getAttributes()['ended_at'] ?? null;
        if (! $start || ! $end) {
            $from = $shift->shift_date?->toDateString();
            $to = $shift->absence_end?->toDateString() ?? $from;

            return $from ? $this->enumerateDates($from, $to) : [];
        }
        $from = substr((string) $start, 0, 10);
        $to = CarbonImmutable::parse($end, 'Europe/Moscow')->subSecond()->toDateString();

        return $this->enumerateDates($from, $to);
    }

    private function occupiesDate(WorkShift $shift, string $date): bool
    {
        $start = $shift->getAttributes()['started_at'] ?? null;
        $end = $shift->getAttributes()['ended_at'] ?? null;
        if ($start && $end) {
            $first = substr((string) $start, 0, 10);
            $last = CarbonImmutable::parse($end, 'Europe/Moscow')->subSecond()->toDateString();

            return $first <= $date && $date <= $last;
        }
        $from = $shift->shift_date?->toDateString();
        $to = $shift->absence_end?->toDateString() ?? $from;

        return $from && $from <= $date && $date <= $to;
    }

    private function enumerateDates(string $from, string $to): array
    {
        if ($to < $from) {
            return [$from];
        }
        $dates = [];
        for ($day = CarbonImmutable::parse($from, 'Europe/Moscow'); $day->toDateString() <= $to; $day = $day->addDay()) {
            $dates[] = $day->toDateString();
            if (count($dates) >= 366) {
                break;
            }
        }

        return $dates;
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
