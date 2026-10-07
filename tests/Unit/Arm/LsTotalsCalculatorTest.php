<?php

namespace Tests\Unit\Arm;

use App\Models\ArmExtraPay;
use App\Models\ArmMonthNorm;
use App\Models\ArmPersonnel;
use App\Models\NaryadAssignment;
use App\Models\NaryadNorm;
use App\Models\User;
use App\Services\Arm\LsTotalsCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LsTotalsCalculatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_vc1_subtracts_holiday_hours(): void
    {
        NaryadNorm::create([
            'year_hours' => 1, 'month_hours' => 1, 'week_hours' => 1, 'min_rest_hours' => 1,
        ]);
        $user = User::factory()->driver()->create();
        $personnel = ArmPersonnel::create([
            'user_id' => $user->id,
            'tab_number' => '1001',
            'full_name' => 'Т',
            'position_code' => 'МШ',
        ]);
        $row = new NaryadAssignment([
            'user_id' => $user->id,
            'plan_date' => '2026-04-10',
            'route_number' => '25',
            'shift_code' => '2',
            'hours_line' => 8,
            'hours_holiday' => 2,
        ]);
        $t = (new LsTotalsCalculator)->calculate($personnel, '2026-04', collect([$row]));
        $this->assertEquals(8.0, $t['vchas1']);
        $this->assertEquals(6.0, $t['vc1']);
        $this->assertEquals(2.0, $t['prazd1']);
    }

    public function test_nvih_hours_from_days_times_dnnr(): void
    {
        $user = User::factory()->driver()->create();
        $personnel = ArmPersonnel::create([
            'user_id' => $user->id,
            'tab_number' => '1002',
            'full_name' => 'Т',
            'position_code' => 'МШ',
        ]);
        ArmMonthNorm::create(['year_month' => '2026-04', 'month_hours' => 150, 'day_hours' => 6.054]);
        ArmExtraPay::create([
            'year_month' => '2026-04',
            'user_id' => $user->id,
            'tab_number' => '1002',
            'position_code' => 'МШ',
            'extra_days_off' => 1,
        ]);
        $t = (new LsTotalsCalculator)->calculate($personnel, '2026-04', collect());
        $this->assertEqualsWithDelta(6.054, $t['nvih'], 0.0001);
        $this->assertEqualsWithDelta(6.054, $t['dobnv'], 0.0001);
    }

    public function test_absence_hours_without_shift_go_to_allchas_not_vchas1(): void
    {
        NaryadNorm::create([
            'year_hours' => 1, 'month_hours' => 1, 'week_hours' => 1, 'min_rest_hours' => 1,
        ]);
        $user = User::factory()->driver()->create();
        $personnel = ArmPersonnel::create([
            'user_id' => $user->id,
            'tab_number' => '1003',
            'full_name' => 'Т',
            'position_code' => 'МШ',
        ]);
        $work = new NaryadAssignment([
            'user_id' => $user->id,
            'plan_date' => '2026-04-10',
            'route_number' => '25',
            'work_code' => '25',
            'shift_code' => '2',
            'hours_line' => 8,
        ]);
        $sick = new NaryadAssignment([
            'user_id' => $user->id,
            'plan_date' => '2026-04-11',
            'route_number' => 'Б',
            'work_code' => 'Б',
            'hours_line' => 6.133,
        ]);
        $t = (new LsTotalsCalculator)->calculate($personnel, '2026-04', collect([$work, $sick]));
        $this->assertEquals(8.0, $t['vchas1']);
        $this->assertEquals(1.0, $t['dni']);
        $this->assertEqualsWithDelta(14.133, $t['allchas'], 0.001);
        $this->assertEqualsWithDelta(6.133, $t['otvl'], 0.001);
    }
}
