<?php

namespace App\Services\Payroll;

use App\Models\User;
use App\Models\WorkShift;
use App\Models\WorkTimeMonthSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** Read-only derived personal report. No recalculation of stored rows or official ARM accounts. */
class PersonalWorkTimeMonth
{
    public function __construct(private PersonalWorkTimeCalculator $calculator) {}

    public function summarize(User $actor, string $month): array
    {
        Validator::make(['month' => $month], ['month' => 'required|date_format:Y-m'])->validate();

        return DB::transaction(function () use ($actor, $month) {
            // Same per-user lock as create(): the report cannot observe half a concurrent insert.
            $user = User::whereKey($actor->id)->lockForUpdate()->first();
            abort_unless($user?->is_active, 403);
            $from = CarbonImmutable::parse($month.'-01', 'Europe/Moscow');
            $to = $from->addMonth();
            $absenceQuery = WorkShift::where('user_id', $user->id)->where('calculation_version', 2)->where('type', 'deviation')->whereNotNull('absence_kind');
            $absences = (clone $absenceQuery)->where('started_at', '<', $to->format('Y-m-d H:i:s'))
                ->where('ended_at', '>', $from->format('Y-m-d H:i:s'))->orderBy('shift_date')->get();
            $base = WorkShift::where('user_id', $user->id)->where('type', 'work');
            $selected = (clone $base)->where('shift_date', '>=', $from->toDateString())->where('shift_date', '<', $to->toDateString())->get();
            $before = (clone $base)->where('shift_date', '<', $from->toDateString())->orderByDesc('shift_date')->orderByDesc('started_at')->orderByDesc('id')->limit(3)->get();
            $after = (clone $base)->where('shift_date', '>=', $to->toDateString())->orderBy('shift_date')->orderBy('started_at')->orderBy('id')->limit(3)->get();
            $rows = $before->concat($selected)->concat($after)->unique('id')
                ->sortBy(fn ($s) => ($s->getRawOriginal('started_at') ?? $s->shift_date->toDateString()).sprintf('%020d', $s->id))->values();
            $ids = array_fill_keys($selected->modelKeys(), true);
            $states = [];
            foreach ($rows as $shift) {
                if ($shift->calculation_version === 2) {
                    app(PersonalWorkTimeSettings::class)->validate($shift->calculation_snapshot);
                    $states[$shift->id] = $shift->calculation_result;
                }
            }
            $groups = [];
            $pairs = [];
            $warnings = [];
            for ($i = 0; $i < $rows->count(); $i++) {
                $first = $rows[$i];
                $second = $rows[$i + 1] ?? null;
                $group = [$first];
                if ($second && $this->pair($first, $second)) {
                    $group[] = $second;
                    $pair = $this->reconcile($first, $second, $states);
                    if (isset($ids[$first->id]) || isset($ids[$second->id])) {
                        $pairs[] = $pair;
                        if ($pair['status'] === 'incompatible_settings') {
                            $warnings[] = 'night_pair_settings_conflict';
                        }
                    }
                    $i++;
                }
                $groups[] = $group;
            }
            $restDates = $this->restDates($user->id, $rows);
            $contextAbsences = $rows->isEmpty() ? collect() : (clone $absenceQuery)
                ->where('started_at', '<', $rows->last()->getRawOriginal('ended_at'))
                ->where('ended_at', '>', $rows->first()->getRawOriginal('started_at'))->get();
            $rests = [];
            foreach ($groups as $i => $current) {
                if ($i === 0) {
                    continue;
                }
                $previous = $groups[$i - 1];
                if (! $this->selected($current, $ids) && ! $this->selected($previous, $ids)) {
                    continue;
                }
                $rest = $this->rest($previous, $current, $restDates);
                foreach ($contextAbsences as $absence) {
                    if ($absence->getRawOriginal('started_at') < ($rest['next_started_at'] ?? '')
                        && $absence->getRawOriginal('ended_at') > ($rest['previous_ended_at'] ?? '')) {
                        $rest['required_minutes'] = null;
                        $rest['minimum_required_minutes'] = null;
                        $rest['violation'] = null;
                        $rest['calendar_confirmed'] = false;
                        $rest['rest_days'] = null;
                        $rest['absence_context'] = true;
                        $warnings[] = 'absence_rest_context';
                    }
                }
                $rests[] = $rest;
                if (! $rest['calendar_confirmed']) {
                    $warnings[] = 'rest_calendar_incomplete';
                }
                if ($rest['legacy_context']) {
                    $warnings[] = 'legacy_rest_context';
                }
            }
            $totals = array_fill_keys(['paid_minutes', 'paid_break_minutes', 'actual_night_minutes', 'night_minutes', 'evening_minutes', 'subtotal_kopecks', 'base_kopecks', 'evening_kopecks', 'night_kopecks'], 0);
            $byKind = [];
            foreach (['line', 'reserve', 'surface'] as $kind) {
                $byKind[$kind] = array_fill_keys(['paid_minutes', 'actual_night_minutes', 'actual_evening_minutes', 'night_minutes', 'evening_minutes', 'base_kopecks', 'evening_kopecks', 'night_kopecks'], 0);
            }
            $output = [];
            foreach ($selected->sortBy('started_at') as $shift) {
                if ($shift->calculation_version !== 2) {
                    continue;
                }
                $result = $states[$shift->id];
                $result['pending'] = array_values(array_diff($result['pending'], ['rest_chain_and_monthly_rollup']));
                foreach (['paid_minutes', 'paid_break_minutes', 'actual_night_minutes', 'night_minutes', 'evening_minutes', 'subtotal_kopecks'] as $key) {
                    $totals[$key] += $result[$key];
                }
                foreach ($result['by_kind'] as $name => $kind) {
                    foreach ($byKind[$name] as $key => $_) {
                        $byKind[$name][$key] += $kind[$key];
                    }
                    foreach (['base_kopecks', 'evening_kopecks', 'night_kopecks'] as $key) {
                        $totals[$key] += $kind[$key];
                    }
                }
                $output[] = ['id' => $shift->id, 'shift_date' => $shift->shift_date->toDateString(),
                    'started_at' => $shift->getAttributes()['started_at'], 'ended_at' => $shift->getAttributes()['ended_at'],
                    'shift_code' => $shift->shift_code,
                    'original_subtotal_kopecks' => $shift->calculation_result['subtotal_kopecks'], 'result' => $result];
            }
            $legacy = $selected->filter(fn ($s) => $s->calculation_version !== 2)->count();
            if ($legacy) {
                $warnings[] = 'legacy_shifts_excluded';
            }
            if (collect($output)->contains(fn ($s) => in_array('night_continuation_reconciliation', $s['result']['pending'], true))) {
                $warnings[] = 'night_pair_incomplete';
            }

            return [
                'month' => $month, 'attribution' => 'shift_start_date', 'shifts' => $output,
                'totals' => $totals, 'by_kind_totals' => $byKind, 'night_pairs' => $pairs, 'rest_intervals' => $rests,
                'legacy_shifts_count' => $legacy,
                'absences' => $absences->map(fn ($absence) => [
                    'id' => $absence->id, 'revision' => $absence->revision, 'absence_kind' => $absence->absence_kind,
                    'label' => PersonalWorkAbsenceCalculator::LABELS[$absence->absence_kind] ?? 'Отвлечение',
                    'shift_date' => $absence->shift_date->toDateString(), 'absence_end' => $absence->absence_end->toDateString(),
                    'days_in_month' => (int) max($from, CarbonImmutable::parse($absence->shift_date->toDateString(), 'Europe/Moscow'))
                        ->diffInDays(min($to->subDay(), CarbonImmutable::parse($absence->absence_end->toDateString(), 'Europe/Moscow'))) + 1,
                    'payment_status' => 'not_calculated',
                ])->values()->all(),
                'excluded_deviations_count' => WorkShift::where('user_id', $user->id)->where('calculation_version', 1)->where('type', '!=', 'work')->whereBetween('shift_date', [$from->toDateString(), $to->subDay()->toDateString()])->count(),
                'warnings' => array_values(array_unique($warnings)), 'calculation_complete' => false,
                'pending' => ['qualification_seniority_premium_overtime_deductions', 'full_cntsmen_procedural_parity'],
            ];
        });
    }

    private function pair(WorkShift $a, WorkShift $b): bool
    {
        return $a->calculation_version === 2 && $b->calculation_version === 2
            && in_array($a->shift_code, $a->calculation_snapshot['night_pair_codes'] ?? ['3+', '4+'], true)
            && str_starts_with((string) $b->shift_code, '1')
            && $a->shift_date->copy()->addDay()->toDateString() === $b->shift_date->toDateString()
            && $this->time($b, 'started_at')->greaterThanOrEqualTo($this->time($a, 'ended_at'));
    }

    private function reconcile(WorkShift $a, WorkShift $b, array &$states): array
    {
        $left = $a->calculation_snapshot;
        $right = $b->calculation_snapshot;
        $meta = ['shift_ids' => [$a->id, $b->id], 'applied' => false, 'status' => 'below_threshold'];
        foreach (['timezone', 'night_from_minute', 'night_to_minute', 'rounding_decimals'] as $key) {
            if ($left[$key] !== $right[$key]) {
                return array_merge($meta, ['status' => 'incompatible_settings']);
            }
        }
        if (($left['night_pair_merge'] ?? true) !== ($right['night_pair_merge'] ?? true)) {
            return array_merge($meta, ['status' => 'incompatible_settings']);
        }
        // UCHET.rs_noch uses differences from NR_NOCH1 and to NR_NOCH2, not the gap as worked time.
        $nightStart = CarbonImmutable::parse($a->shift_date->toDateString(), $left['timezone'])->addMinutes($left['night_from_minute']);
        $nightEnd = CarbonImmutable::parse($b->shift_date->toDateString(), $left['timezone'])->addMinutes($left['night_to_minute']);
        $parts = [max(0, intdiv($this->time($a, 'ended_at')->getTimestamp() - $nightStart->getTimestamp(), 60)), max(0, intdiv($nightEnd->getTimestamp() - $this->time($b, 'started_at')->getTimestamp(), 60))];
        $applied = ($left['night_pair_merge'] ?? true) && $this->calculator->nightMajority($parts, [$states[$a->id]['paid_minutes'], $states[$b->id]['paid_minutes']], $left['rounding_decimals']);
        foreach ([$a, $b] as $shift) {
            if ($applied) {
                $states[$shift->id] = $this->calculator->creditAllNight($states[$shift->id], $shift->calculation_snapshot);
            }
            $states[$shift->id]['pending'] = array_values(array_diff($states[$shift->id]['pending'], ['night_continuation_reconciliation']));
        }

        return array_merge($meta, ['applied' => $applied, 'status' => ! ($left['night_pair_merge'] ?? true) ? 'disabled' : ($applied ? 'all_night' : 'below_threshold')]);
    }

    private function rest(array $previous, array $current, array $dates): array
    {
        $last = $previous[array_key_last($previous)];
        $next = $current[0];
        $base = ['previous_shift_ids' => array_map(fn ($s) => $s->id, $previous), 'next_shift_ids' => array_map(fn ($s) => $s->id, $current), 'legacy_context' => $last->calculation_version !== 2 || $next->calculation_version !== 2];
        $base['previous_ended_at'] = $last->getAttributes()['ended_at'] ?? null;
        $base['next_started_at'] = $next->getAttributes()['started_at'] ?? null;
        if (! $last->ended_at || ! $next->started_at || $next->calculation_version !== 2) {
            $actual = $last->ended_at && $next->started_at
                ? ($this->time($next, 'started_at')->getTimestamp() - $this->time($last, 'ended_at')->getTimestamp()) / 60 : null;

            return $base + ['actual_minutes' => $actual, 'required_minutes' => null, 'minimum_required_minutes' => null, 'rest_days' => null, 'violation' => null, 'calendar_confirmed' => false];
        }
        $gapDays = max(0, (int) $last->shift_date->diffInDays($next->shift_date) - 1);
        $confirmed = count(array_filter(array_keys($dates), fn ($d) => $d > $last->shift_date->toDateString() && $d < $next->shift_date->toDateString()));
        $known = $gapDays === $confirmed;
        $duty = array_sum(array_map(fn ($s) => $s->calculation_version === 2 ? $s->calculation_result['paid_minutes'] : ($s->started_at && $s->ended_at ? max(0, intdiv($this->time($s, 'ended_at')->getTimestamp() - $this->time($s, 'started_at')->getTimestamp(), 60)) : 0), $previous));
        $result = $this->calculator->rest($this->time($last, 'ended_at'), $this->time($next, 'started_at'), $duty, $known ? $gapDays : 0, $next->calculation_snapshot);
        $result['minimum_required_minutes'] = $result['required_minutes'];
        if (! $known) {
            $result['required_minutes'] = null;
            $result['violation'] = $result['violation'] ? true : null;
        }

        return $base + $result + ['calendar_confirmed' => $known, 'rest_days' => $known ? $gapDays : null];
    }

    private function restDates(int $userId, $rows): array
    {
        if ($rows->isEmpty()) {
            return [];
        }
        $dates = [];
        $settings = WorkTimeMonthSettings::where('user_id', $userId)
            ->whereDate('month', '>=', $rows->first()->shift_date->copy()->startOfMonth()->toDateString())
            ->whereDate('month', '<=', $rows->last()->shift_date->copy()->startOfMonth()->toDateString())->get();
        foreach ($settings as $record) {
            $list = $record->settings['rest_dates'] ?? [];
            Validator::make(['dates' => $list], ['dates' => 'array|max:31', 'dates.*' => 'date_format:Y-m-d'])->validate();
            foreach ($list as $date) {
                if (substr($date, 0, 7) === $record->month->format('Y-m')) {
                    $dates[$date] = true;
                }
            }
        }

        return $dates;
    }

    private function selected(array $group, array $ids): bool
    {
        foreach ($group as $shift) {
            if (isset($ids[$shift->id])) {
                return true;
            }
        }

        return false;
    }

    private function time(WorkShift $shift, string $field): CarbonImmutable
    {
        return CarbonImmutable::parse($shift->getRawOriginal($field), $shift->calculation_snapshot['timezone'] ?? 'Europe/Moscow');
    }
}
