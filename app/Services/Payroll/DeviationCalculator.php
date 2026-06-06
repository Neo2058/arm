<?php

namespace App\Services\Payroll;

class DeviationCalculator
{
    public function calculate(array $shift): array
    {
        $type = $data['deviation_id'] ?? null;


        $coef = PayrollRules::DEVIATION_COEFFICIENTS[$type] ?? 1;

        $hours = PayrollRules::DEFAULT_WORK_HOURS;

        return [
            'hours' => $hours,
            'coefficient' => $coef,
        ];
    }
}
