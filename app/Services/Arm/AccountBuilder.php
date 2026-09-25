<?php

namespace App\Services\Arm;

use App\Models\ArmAccount;
use App\Models\ArmAccountLine;
use App\Models\ArmPayFormula;
use App\Models\ArmPersonnel;
use App\Models\NaryadAssignment;
use Carbon\Carbon;

class AccountBuilder
{
    public function __construct(private FormulaInterpreter $interpreter) {}

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

        $accounts = 0;
        $lines = 0;
        foreach ($assignments as $userId => $rows) {
            $account = $this->buildForUser((int) $userId, $yearMonth, $rows);
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

        $personnel = ArmPersonnel::query()->where('user_id', $userId)->first();
        $position = $personnel?->position_code ?: 'МШ';
        $formulaKind = str_contains((string) $position, 'П') ? 'assistant' : 'machinist';
        $totals = $this->sumTotals($assignments);
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
            ArmAccountLine::create([
                'arm_account_id' => $account->id,
                'sort' => ++$sort,
                'name' => $formula->name,
                'pay_code' => $formula->pay_code,
                'tariff_code' => $formula->tariff_code,
                'tariff' => $formula->tariff,
                'percent' => $formula->percent,
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
        $t = [
            'vchas1' => 0.0, 'vc1' => 0.0,
            'vchas2' => 0.0, 'vc2' => 0.0,
            'vchasr' => 0.0, 'vcr' => 0.0,
            'vchas2p' => 0.0, 'vc2p' => 0.0,
            'vchasrp' => 0.0, 'vcrp' => 0.0,
            'noch1' => 0.0, 'noch2' => 0.0, 'nochr' => 0.0, 'noch2p' => 0.0, 'nochrp' => 0.0,
            'razr1' => 0.0, 'razr2' => 0.0, 'razrr' => 0.0, 'razr2p' => 0.0, 'razrrp' => 0.0,
            'vech1' => 0.0, 'vech2' => 0.0, 'vechr' => 0.0, 'vech2p' => 0.0, 'vechrp' => 0.0,
            'prazd1' => 0.0, 'prazd2' => 0.0, 'prazdr' => 0.0, 'prazd2p' => 0.0, 'prazdrp' => 0.0,
            'per1' => 0.0, 'nvih' => 0.0, 'dobnv' => 0.0,
            'dob1' => 0.0, 'dob2' => 0.0, 'dobr' => 0.0, 'dob2p' => 0.0, 'dobrp' => 0.0,
        ];
        foreach ($assignments as $row) {
            $t['vchas1'] += (float) $row->hours_line;
            $t['vchas2'] += (float) $row->hours_line_2;
            $t['vchasr'] += (float) $row->hours_reserve;
            $t['vchas2p'] += (float) $row->hours_line_2_reserve;
            $t['vchasrp'] += (float) $row->hours_reserve_2;
            $t['noch1'] += (float) $row->hours_night;
            $t['noch2'] += (float) $row->hours_night_2;
            $t['nochr'] += (float) $row->hours_night_reserve;
            $t['noch2p'] += (float) $row->hours_night_2_reserve;
            $t['vech1'] += (float) $row->hours_evening;
            $t['vech2'] += (float) $row->hours_evening_2;
            $t['vechr'] += (float) $row->hours_evening_reserve;
            $t['razr1'] += (float) $row->hours_break;
            $t['razr2'] += (float) $row->hours_break_2;
            $t['razrr'] += (float) $row->hours_break_reserve;
            $t['prazd1'] += (float) $row->hours_holiday;
            $t['prazd2'] += (float) $row->hours_holiday_2;
            $t['per1'] += (float) $row->hours_overtime;
        }
        $t['vc1'] = $t['vchas1'];
        $t['vc2'] = $t['vchas2'];
        $t['vcr'] = $t['vchasr'];
        $t['vc2p'] = $t['vchas2p'];
        $t['vcrp'] = $t['vchasrp'];

        return $t;
    }
}
