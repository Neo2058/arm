<?php

namespace App\Services\Payroll;

class PayrollService
{
    public function __construct(
        protected ShiftCalculator $shiftCalculator,
        protected DeviationCalculator $deviationCalculator,
        protected SalaryCalculator $salaryCalculator
    ) {}

    public function calculate(array $shift, $user): array
    {
        if ($shift['type'] === 'work') {

            $base = $this->shiftCalculator->calculate($shift);

            $money = $this->salaryCalculator->calculateBase(
                $base['hours'],
                $user
            );

            return [
                'type' => 'work',
                ...$base,
                'earnings' => $money,
            ];
        }

        $base = $this->deviationCalculator->calculate($shift);

        $money = $this->salaryCalculator->calculateBase(
                $base['hours'],
                $user
            ) * $base['coefficient'];

        return [
            'type' => 'deviation',
            ...$base,
            'earnings' => $money,
        ];
    }
}
