<?php

namespace Tests\Unit\Arm;

use App\Services\Arm\UnderworkAllocator;
use Tests\TestCase;

class UnderworkAllocatorTest extends TestCase
{
    public function test_spreads_underwork_first_then_second(): void
    {
        $first = $this->base([
            'tek_nr' => 80,
            'vc1' => 50,
            'prazd1' => 10,
            'dob1' => 10,
        ]);
        $second = $this->base([
            'tek_nr' => 80,
            'vc1' => 60,
            'prazd1' => 8,
            'dob1' => 8,
        ]);

        [$a, $b] = (new UnderworkAllocator)->apply($first, $second);

        $this->assertEquals(10.0, $a['dob1']);
        $this->assertEquals(8.0, $b['dob1']);
    }

    public function test_overtime_on_second_clears_dob_except_dobnv(): void
    {
        $first = $this->base(['tek_nr' => 80, 'vc1' => 90, 'dob1' => 4, 'dobnv' => 2]);
        $second = $this->base(['tek_nr' => 80, 'vc1' => 90, 'per1' => 20, 'dob1' => 5, 'dobnv' => 3]);

        [$a, $b] = (new UnderworkAllocator)->apply($first, $second);

        $this->assertEquals(0.0, $a['dob1']);
        $this->assertEquals(2.0, $a['dobnv']);
        $this->assertEquals(0.0, $b['dob1']);
        $this->assertEquals(3.0, $b['dobnv']);
    }

    /**
     * @param  array<string, float>  $over
     * @return array<string, float>
     */
    private function base(array $over): array
    {
        return array_merge([
            'tek_nr' => 0, 'otvl' => 0, 'per1' => 0,
            'vc1' => 0, 'vc2' => 0, 'vcr' => 0, 'vcr2' => 0, 'vc2p' => 0, 'vcrp' => 0,
            'nvih' => 0, 'prazd1' => 0, 'prazd2' => 0, 'prazdr' => 0,
            'prazdr2' => 0, 'prazd2p' => 0, 'prazdrp' => 0,
            'dobnv' => 0, 'dob1' => 0, 'dob2' => 0, 'dobr' => 0,
            'dobr2' => 0, 'dob2p' => 0, 'dobrp' => 0,
        ], $over);
    }
}
