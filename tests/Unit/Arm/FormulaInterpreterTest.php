<?php

namespace Tests\Unit\Arm;

use App\Services\Arm\FormulaInterpreter;
use Tests\TestCase;

class FormulaInterpreterTest extends TestCase
{
    public function test_evaluates_flsm_main_hours_iif(): void
    {
        $formula = 'IIF((Vchas1+Vchas2+Vchasr>=Vchas2p+Vchasrp).AND.Vchas1>=Vchas2.AND.Vchas1>=Vchasr,Vc1-per1+dob1-nvih+dobnv,vc1+dob1)';
        $value = (new FormulaInterpreter)->evaluate($formula, [
            'Vchas1' => 104.5, 'Vchas2' => 0, 'Vchasr' => 0,
            'Vchas2p' => 0, 'Vchasrp' => 0,
            'Vc1' => 104.5, 'vc1' => 104.5,
            'per1' => 0, 'dob1' => 0, 'nvih' => 0, 'dobnv' => 0,
        ]);
        $this->assertEquals(104.5, $value);
    }

    public function test_iif_else_branch(): void
    {
        $value = (new FormulaInterpreter)->evaluate(
            'IIF(Vchas1>Vchas2,1,2)',
            ['Vchas1' => 1, 'Vchas2' => 5]
        );
        $this->assertEquals(2.0, $value);
    }
}
