<?php

namespace App\Services\Payroll;

class PayrollRules
{
    public const HOURLY_RATE = 450;

    public const PREMIUM = 0.32;

    public const DEVIATION_COEFFICIENTS = [
        'sick' => 0.8,
        'training' => 1.0,
        'study_leave' => 0.7,
    ];
    public const DEFAULT_WORK_HOURS = 8;
}
