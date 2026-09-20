<?php

namespace Tests\Unit\Arm;

use App\Models\ArmShiftBreakdown;
use App\Models\NaryadAssignment;
use App\Models\NaryadQuota;
use App\Models\ScheduleType;
use App\Models\User;
use App\Services\Arm\ShiftHoursService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShiftHoursServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_parses_grid_route_key(): void
    {
        $service = new ShiftHoursService;

        $this->assertSame(['25', '1'], $service->parseRouteKey('25 (1-с ночи)'));
        $this->assertSame(['МЗ-1', ''], $service->parseRouteKey('МЗ-1'));
    }

    public function test_finds_breakdown_by_day_graph_and_applies_hours(): void
    {
        $type = ScheduleType::create([
            'name' => 'Рабочий',
            'foxpro_code' => '01',
            'routes_count' => 8,
            'people_per_route' => 2,
        ]);
        NaryadQuota::create([
            'plan_date' => '2026-03-10',
            'schedule_type_id' => $type->id,
            'required_crews' => 4,
        ]);
        $breakdown = ArmShiftBreakdown::create([
            'schedule_type_id' => $type->id,
            'graph_code' => '01',
            'route_code' => '25',
            'shift_code' => '1',
            'sequence' => '1',
            'hours_total' => 7.4,
            'hours_line' => 6.2,
            'hours_night' => 1.2,
        ]);

        $driver = User::factory()->driver()->create();
        $assignment = NaryadAssignment::create([
            'user_id' => $driver->id,
            'plan_date' => '2026-03-10',
            'route_number' => '25 (1-с ночи)',
        ]);

        $service = new ShiftHoursService;
        $this->assertSame(7.4, $service->hoursForRouteOnDate('2026-03-10', '25 (1-с ночи)'));
        $this->assertTrue($service->applyToAssignment($assignment));

        $assignment->refresh();
        $this->assertSame($breakdown->id, $assignment->arm_shift_breakdown_id);
        $this->assertEquals(7.4, $assignment->hours_total);
        $this->assertEquals(6.2, $assignment->hours_line);
        $this->assertEquals(1.2, $assignment->hours_night);
        $this->assertSame('1', $assignment->shift_code);
    }
}
