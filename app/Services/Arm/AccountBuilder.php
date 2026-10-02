<?php

namespace App\Services\Arm;

use App\Models\ArmAccount;
use App\Models\ArmAccountLine;
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
    ) {}

    /**
     * @return array{accounts: int, lines: int}
     */
    public function buildMonth(string $yearMonth): array
    {
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
            $account = $this->buildForUser((int) $userId, $yearMonth, $assignments->get($userId, collect()));
            $accounts++;
            $lines += $account->lines()->count();
        }

        return ['accounts' => $accounts, 'lines' => $lines];
    }

    public function buildForUser(int $userId, string $yearMonth, $assignments = null): ArmAccount
    {
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
        $position = $personnel?->position_code ?: 'МШ';
        $formulaKind = str_contains((string) $position, 'П') ? 'assistant' : 'machinist';
        $totals = $this->totalsCalculator->calculate($personnel, $yearMonth, $assignments);
        $profession = $personnel?->category_code ?: $personnel?->position_code;

        $account = ArmAccount::updateOrCreate(
            [
                'user_id' => $userId,
                'year_month' => $yearMonth,
                'kind' => 'primary',
            ],
            [
                'position_code' => $position,
                'formula_kind' => $formulaKind,
                'totals' => $totals,
                'status' => 'draft',
            ]
        );
        $account->lines()->delete();

        $formulas = ArmPayFormula::query()
            ->where('kind', $formulaKind)
            ->where('selected', true)
            ->orderBy('nom')
            ->get();

        $sort = 0;
        foreach ($formulas as $formula) {
            if (! filled($formula->formula)) {
                continue;
            }
            $hours = $this->interpreter->evaluate($formula->formula, $totals);
            if (abs($hours) < 0.0005) {
                continue;
            }
            $percent = $this->linePercent($formula, $totals);
            $tariff = $this->lineTariff($formula);
            ArmAccountLine::create([
                'arm_account_id' => $account->id,
                'sort' => ++$sort,
                'name' => $formula->name,
                'pay_code' => $formula->pay_code,
                'tariff_code' => $formula->tariff_code,
                'tariff' => $tariff,
                'percent' => $percent,
                'hours' => round($hours, 2),
                'cost_code' => $formula->cost_code,
                'profession' => $profession,
                'ls_number' => '0',
                'formula_id' => $formula->id,
            ]);
        }

        return $account->load('lines');
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
}
