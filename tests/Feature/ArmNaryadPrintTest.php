<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckDeviceBinding;
use App\Http\Middleware\CheckDynamicBarrier;
use App\Models\ArmPersonnel;
use App\Models\ArmShiftBreakdown;
use App\Models\NaryadAssignment;
use App\Models\NaryadNorm;
use App\Models\NaryadQuota;
use App\Models\ScheduleType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ArmNaryadPrintTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->withoutMiddleware([
            CheckDeviceBinding::class,
            CheckDynamicBarrier::class,
        ]);
        NaryadNorm::create([
            'year_hours' => 5000,
            'month_hours' => 400,
            'week_hours' => 80,
            'min_rest_hours' => 8,
        ]);
    }

    public function test_dispatcher_prints_naryad_for_date(): void
    {
        $dispatcher = User::factory()->dispatcher()->create(['name' => 'Нарядчик']);
        $type = ScheduleType::create([
            'name' => 'Рабочий',
            'foxpro_code' => '01',
            'routes_count' => 4,
            'people_per_route' => 2,
        ]);
        NaryadQuota::create([
            'plan_date' => '2026-04-10',
            'schedule_type_id' => $type->id,
            'required_crews' => 2,
        ]);
        ArmShiftBreakdown::create([
            'graph_code' => '01',
            'route_code' => '25',
            'shift_code' => '2',
            'sequence' => '1',
            'start_hours' => 5.30,
            'end_hours' => 14.20,
            'appearance_start' => 'Печат',
            'appearance_end' => 'Печат',
        ]);
        $driver = User::factory()->driver()->create(['name' => 'ИВАНОВ']);
        ArmPersonnel::create([
            'user_id' => $driver->id,
            'tab_number' => '0313',
            'full_name' => 'ИВАНОВ И.И.',
            'position_code' => 'МШ',
        ]);
        NaryadAssignment::create([
            'user_id' => $driver->id,
            'plan_date' => '2026-04-10',
            'route_number' => '25 (2-ранняя)',
        ]);

        $this->actingAs($dispatcher)
            ->get('/naryad/partial/print?date=2026-04-10')
            ->assertOk()
            ->assertSee('Печать нарядов', false)
            ->assertSee('НАРЯД ЛОКОМОТИВНЫХ БРИГАД НА 10.04.2026', false)
            ->assertSee('ИВАНОВ', false);

        $this->actingAs($dispatcher)
            ->get('/naryad/print?date=2026-04-10&kind=full')
            ->assertOk()
            ->assertSee('НАРЯД ЛОКОМОТИВНЫХ БРИГАД НА 10.04.2026', false)
            ->assertSee('ИВАНОВ', false);
    }

    public function test_operator_cannot_print_naryad(): void
    {
        $operator = User::factory()->operator()->create();
        $this->actingAs($operator)->get('/naryad/print?date=2026-04-10')->assertForbidden();
        $this->actingAs($operator)->get('/naryad/partial/print')->assertForbidden();
    }
}
