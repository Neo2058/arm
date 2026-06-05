<?php

namespace App\Services\Payroll;

class SalaryCalculator
{
    public function calculateBase(float $hours, object $user): float
    {
        $base = PayrollRules::HOURLY_RATE;

        $premium = PayrollRules::PREMIUM;

        $seniority = $user->seniority_percent ?? 0;

        $qualification = $user->qualification_percent ?? 0;

        $totalMultiplier =
            1 +
            $premium +
            $seniority +
            $qualification;

        return $hours * $base * $totalMultiplier;
    }
}
