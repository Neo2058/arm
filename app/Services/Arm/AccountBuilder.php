<?php

namespace App\Services\Arm;

use App\Models\ArmAccount;
use App\Models\ArmAccountLine;
use App\Models\ArmAppointment;
use App\Models\ArmExtraPay;
use App\Models\ArmMonthPremium;
use App\Models\ArmPayFormula;
use App\Models\ArmPersonnel;
use App\Models\ArmTariff;
use App\Models\NaryadAssignment;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class AccountBuilder
{
    public function __construct(
        private FormulaInterpreter $interpreter,
        private LsTotalsCalculator $totalsCalculator,
        private UnderworkAllocator $underwork,
    ) {}

    /**
     * @return array{accounts: int, lines: int, kind: string}
     */
    public function buildMonth(string $yearMonth, string $kind = 'primary'): array
    {
        $kind = $this->normalizeKind($kind);
        $start = Carbon::createFromFormat('Y-m', $yearMonth)->startOfMonth();
        $end = $start->copy()->endOfMonth();

        $assignments = NaryadAssignment::query()
            ->whereBetween('plan_date', [$start->toDateString(), $end->toDateString()])
            ->get()
            ->groupBy('user_id');

        $userIds = collect($assignments->keys())
            ->merge(ArmExtraPay::query()->where('year_month', $yearMonth)->whereNotNull('user_id')->pluck('user_id'))
            ->merge(ArmMonthPremium::query()->where('year_month', $yearMonth)->whereNotNull('user_id')->pluck('user_id'))
            ->unique()
            ->filter();

        $accounts = 0;
        $lines = 0;
        foreach ($userIds as $userId) {
            $built = $this->buildAccountsForUser((int) $userId, $yearMonth, $assignments->get($userId, collect()), $kind);
            $accounts += $built->count();
            $lines += $built->sum(fn (ArmAccount $account) => $account->lines->count());
        }

        return ['accounts' => $accounts, 'lines' => $lines, 'kind' => $kind];
    }

    public function buildForUser(int $userId, string $yearMonth, $assignments = null, string $kind = 'primary'): ArmAccount
    {
        $built = $this->buildAccountsForUser($userId, $yearMonth, $assignments, $kind);

        return $built->firstWhere('ls_number', '0') ?? $built->first();
    }

    /**
     * @return Collection<int, ArmAccount>
     */
    public function buildAccountsForUser(int $userId, string $yearMonth, $assignments = null, string $kind = 'primary'): Collection
    {
        $kind = $this->normalizeKind($kind);
        $start = Carbon::createFromFormat('Y-m', $yearMonth)->startOfMonth();
        $end = $start->copy()->endOfMonth();
        if ($assignments === null) {
            $assignments = NaryadAssignment::query()
                ->where('user_id', $userId)
                ->whereBetween('plan_date', [$start->toDateString(), $end->toDateString()])
                ->get();
        }
        if (! $assignments instanceof Collection) {
            $assignments = collect($assignments);
        }

        $personnel = ArmPersonnel::query()->where('user_id', $userId)->first();
        $split = $this->positionSplit($personnel, $start, $end);
        $periods = $split
            ? [
                [
                    'ls_number' => '0',
                    'from' => $start->copy(),
                    'to' => $split['change_on']->copy()->subDay(),
                    'position' => $split['old_position'],
                    'class' => $split['old_class'],
                    'endk' => false,
                ],
                [
                    'ls_number' => '1',
                    'from' => $split['change_on']->copy(),
                    'to' => $end->copy(),
                    'position' => $split['new_position'],
                    'class' => $split['new_class'],
                    'endk' => true,
                ],
            ]
            : [
                [
                    'ls_number' => $kind === 'repeat' ? '1' : '0',
                    'from' => $start->copy(),
                    'to' => $end->copy(),
                    'position' => $personnel?->position_code ?: 'МШ',
                    'class' => $personnel?->class_code,
                    'endk' => false,
                ],
            ];

        ArmAccount::query()
            ->where('user_id', $userId)
            ->where('year_month', $yearMonth)
            ->where('kind', $kind)
            ->delete();

        $created = collect();
        $totalsList = [];
        foreach ($periods as $period) {
            $slicePersonnel = $this->personnelForPeriod($personnel, $period);
            $totals = $this->totalsCalculator->calculate($slicePersonnel, $yearMonth, $assignments, [
                'from' => $period['from'],
                'to' => $period['to'],
                'endk' => $period['endk'],
            ]);
            $totals['period_from'] = $period['from']->toDateString();
            $totals['period_to'] = $period['to']->toDateString();
            $totalsList[] = $totals;
            $created->push($this->storeAccount($userId, $yearMonth, $kind, $period, $slicePersonnel, $totals));
        }

        if ($created->count() === 2) {
            [$firstTotals, $secondTotals] = $this->underwork->apply($totalsList[0], $totalsList[1]);
            $created[0]->update(['totals' => $firstTotals]);
            $created[1]->update(['totals' => $secondTotals]);
            $this->replaceLines($created[0], $this->personnelForPeriod($personnel, $periods[0]), $firstTotals, $periods[0]['ls_number']);
            $this->replaceLines($created[1], $this->personnelForPeriod($personnel, $periods[1]), $secondTotals, $periods[1]['ls_number']);
        }

        return $created->map(fn (ArmAccount $account) => $account->load('lines'));
    }

    /**
     * @param  iterable<NaryadAssignment>  $assignments
     * @return array<string, float>
     */
    public function sumTotals(iterable $assignments): array
    {
        return $this->totalsCalculator->calculate(null, Carbon::now()->format('Y-m'), $assignments);
    }

    /**
     * @param  array{ls_number: string, from: Carbon, to: Carbon, position: string, class: ?string, endk: bool}  $period
     * @param  array<string, float>  $totals
     */
    private function storeAccount(int $userId, string $yearMonth, string $kind, array $period, ?ArmPersonnel $personnel, array $totals): ArmAccount
    {
        $position = $period['position'] ?: 'МШ';
        $formulaKind = str_contains((string) $position, 'П') ? 'assistant' : 'machinist';
        $account = ArmAccount::create([
            'user_id' => $userId,
            'year_month' => $yearMonth,
            'kind' => $kind,
            'ls_number' => $period['ls_number'],
            'position_code' => $position,
            'formula_kind' => $formulaKind,
            'totals' => $totals,
            'status' => 'draft',
        ]);
        $this->replaceLines($account, $personnel, $totals, $period['ls_number']);

        return $account;
    }

    /**
     * @param  array<string, float>  $totals
     */
    private function replaceLines(ArmAccount $account, ?ArmPersonnel $personnel, array $totals, string $lsNumber): void
    {
        $account->lines()->delete();
        $position = $account->position_code ?: 'МШ';
        $formulaKind = $account->formula_kind ?: (str_contains((string) $position, 'П') ? 'assistant' : 'machinist');
        $profession = $personnel?->category_code ?: $position;
        $formulas = ArmPayFormula::query()
            ->whereIn('kind', ['machinist', 'assistant'])
            ->where('selected', true)
            ->orderByRaw('case when kind = ? then 0 else 1 end', [$formulaKind])
            ->orderBy('nom')
            ->get();

        $sort = 0;
        foreach ($formulas as $formula) {
            if (! filled($formula->formula)) {
                continue;
            }
            $hours = $this->interpreter->evaluate($formula->formula, $totals);
            $sc = $this->scFls($formula, $hours, $totals);
            if ($sc === null) {
                continue;
            }
            ArmAccountLine::create([
                'arm_account_id' => $account->id,
                'sort' => ++$sort,
                'name' => $formula->name,
                'pay_code' => $formula->pay_code,
                'tariff_code' => $formula->tariff_code,
                'tariff' => $sc['tariff'],
                'percent' => $sc['percent'],
                'hours' => $sc['hours'],
                'cost_code' => $formula->cost_code,
                'profession' => $formula->kind === 'assistant'
                    ? (str_contains((string) $position, 'П') ? $position : 'П/М')
                    : $profession,
                'ls_number' => $lsNumber,
                'formula_id' => $formula->id,
            ]);
        }
        $account->unsetRelation('lines');
    }

    /**
     * @return array{change_on: Carbon, old_position: string, old_class: ?string, new_position: string, new_class: ?string}|null
     */
    private function positionSplit(?ArmPersonnel $personnel, Carbon $start, Carbon $end): ?array
    {
        if (! $personnel) {
            return null;
        }
        $query = ArmAppointment::query()->orderBy('appointed_on');
        if ($personnel->user_id) {
            $query->where('user_id', $personnel->user_id);
        } elseif ($personnel->tab_number) {
            $query->where('tab_number', $personnel->tab_number);
        } else {
            return null;
        }
        $upto = (clone $query)->whereDate('appointed_on', '<=', $end->toDateString())->get();
        if ($upto->isEmpty()) {
            return null;
        }
        $last = $upto->last();
        if ($last->appointed_on->lt($start) || $last->appointed_on->day === 1) {
            return null;
        }
        $prev = $upto->filter(fn (ArmAppointment $row) => $row->appointed_on->lt($last->appointed_on))->last();
        if (! $prev) {
            $prev = ArmAppointment::query()
                ->when($personnel->user_id, fn ($q) => $q->where('user_id', $personnel->user_id))
                ->when(! $personnel->user_id && $personnel->tab_number, fn ($q) => $q->where('tab_number', $personnel->tab_number))
                ->whereDate('appointed_on', '<', $last->appointed_on->toDateString())
                ->orderByDesc('appointed_on')
                ->first();
        }
        if (! $prev) {
            return null;
        }
        $oldPos = trim((string) $prev->position_code);
        $newPos = trim((string) $last->position_code);
        if ($oldPos === '' || $newPos === '' || $oldPos === $newPos) {
            return null;
        }

        return [
            'change_on' => $last->appointed_on,
            'old_position' => $oldPos,
            'old_class' => $prev->class_code,
            'new_position' => $newPos,
            'new_class' => $last->class_code,
        ];
    }

    /**
     * @param  array{position: string, class: ?string}  $period
     */
    private function personnelForPeriod(?ArmPersonnel $personnel, array $period): ?ArmPersonnel
    {
        if (! $personnel) {
            return $personnel;
        }
        $copy = $personnel->replicate();
        $copy->user_id = $personnel->user_id;
        $copy->tab_number = $personnel->tab_number;
        $copy->position_code = $period['position'];
        if (array_key_exists('class', $period) && $period['class'] !== null) {
            $copy->class_code = $period['class'];
        }

        return $copy;
    }

    private function normalizeKind(string $kind): string
    {
        return $kind === 'repeat' ? 'repeat' : 'primary';
    }

    /**
     * @param  array<string, float>  $totals
     */
    private function linePercent(ArmPayFormula $formula, array $totals): float
    {
        $source = strtolower(trim((string) $formula->percent_source));
        if ($source !== '' && array_key_exists($source, $totals)) {
            return (float) $totals[$source];
        }

        return (float) $formula->percent;
    }

    private function lineTariff(ArmPayFormula $formula): float
    {
        $tariff = (float) $formula->tariff;
        if ($tariff != 0.0) {
            return $tariff;
        }
        $code = trim((string) $formula->tariff_code);
        if ($code === '') {
            return 0.0;
        }
        $row = ArmTariff::query()->where('code', $code)->first();

        return (float) ($row?->rate ?? 0);
    }

    /**
     * sc_fls: TARST из N_TARIF, иначе CHTAR; VROTR только если есть тариф или часовая ставка;
     * пустой TARIF и ненулевой CHTAR → PROCNT=100.
     *
     * @param  array<string, float>  $totals
     * @return array{tariff: float, percent: float, hours: float}|null
     */
    private function scFls(ArmPayFormula $formula, float $hours, array $totals): ?array
    {
        $nTariff = $this->lineTariff($formula);
        $tariffCode = trim((string) $formula->tariff_code);
        $percent = $this->linePercent($formula, $totals);
        $hasNamedTariff = $tariffCode !== '';
        $chtar = 0.0;
        if ($hasNamedTariff && abs($nTariff) > 0.0000001) {
            $procText = trim((string) $formula->percent_text);
            if ($procText !== '' && $procText !== '*') {
                $chtar = round($nTariff * $percent / 100, 3);
            }
        } elseif (! $hasNamedTariff && abs($nTariff) > 0.0000001) {
            $chtar = $nTariff;
        }

        if (! $hasNamedTariff && abs($chtar) < 0.0000001) {
            return null;
        }
        if (abs($hours) < 0.0005) {
            return null;
        }

        $procnt = $percent;
        if (! $hasNamedTariff && abs($chtar) > 0.0000001) {
            $procnt = 100.0;
        }

        return [
            'tariff' => $hasNamedTariff ? $nTariff : $chtar,
            'percent' => $procnt,
            'hours' => round($hours, 2),
        ];
    }
}
