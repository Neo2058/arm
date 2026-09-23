<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckDeviceBinding;
use App\Http\Middleware\CheckDynamicBarrier;
use App\Models\ArmAbsence;
use App\Models\ArmDayAdjustment;
use App\Models\ArmPersonnel;
use App\Models\ArmShiftBreakdown;
use App\Models\DeviationsCatalog;
use App\Models\NaryadAssignment;
use App\Models\NaryadNorm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ArmPlanirRulesTest extends TestCase
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

    public function test_blocks_work_on_absence_period(): void
    {
        [$dispatcher, $driver] = $this->pair();
        ArmAbsence::create([
            'user_id' => $driver->id,
            'tab_number' => '1001',
            'starts_on' => '2026-03-10',
            'ends_on' => '2026-03-20',
            'kind_code' => 'Б',
        ]);

        $this->actingAs($dispatcher)
            ->postJson('/naryad/assign', [
                'user_id' => $driver->id,
                'plan_date' => '2026-03-12',
                'route_number' => '25',
            ])
            ->assertStatus(422)
            ->assertJsonFragment(['success' => false]);
    }

    public function test_allows_deviation_cell_during_absence(): void
    {
        [$dispatcher, $driver] = $this->pair();
        DeviationsCatalog::create([
            'name' => 'Больничный',
            'sys_key' => 'sick_test',
            'short_code' => 'Б',
            'hourly_rate' => 0,
            'default_minutes' => 0,
        ]);
        ArmAbsence::create([
            'user_id' => $driver->id,
            'tab_number' => '1001',
            'starts_on' => '2026-03-10',
            'ends_on' => '2026-03-20',
            'kind_code' => 'Б',
        ]);

        $this->actingAs($dispatcher)
            ->postJson('/naryad/assign', [
                'user_id' => $driver->id,
                'plan_date' => '2026-03-12',
                'route_number' => 'Больничный',
            ])
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    public function test_requires_first_shift_after_night(): void
    {
        [$dispatcher, $driver] = $this->pair();
        NaryadAssignment::create([
            'user_id' => $driver->id,
            'plan_date' => '2026-03-10',
            'route_number' => '10 (4+-ночь)',
            'shift_code' => '4+',
        ]);

        $response = $this->actingAs($dispatcher)
            ->postJson('/naryad/assign', [
                'user_id' => $driver->id,
                'plan_date' => '2026-03-11',
                'route_number' => '25 (2-ранняя)',
            ]);
        $response->assertStatus(422);
        $this->assertStringContainsString('После ночной', (string) $response->json('message'));
    }

    public function test_requires_rest_after_first_shift(): void
    {
        [$dispatcher, $driver] = $this->pair();
        NaryadAssignment::create([
            'user_id' => $driver->id,
            'plan_date' => '2026-03-10',
            'route_number' => '25 (1-с ночи)',
            'shift_code' => '1',
        ]);

        $response = $this->actingAs($dispatcher)
            ->postJson('/naryad/assign', [
                'user_id' => $driver->id,
                'plan_date' => '2026-03-11',
                'route_number' => '25 (2-ранняя)',
            ]);
        $response->assertStatus(422);
        $this->assertStringContainsString('выходной', (string) $response->json('message'));
    }

    public function test_blocks_short_interval_between_shifts(): void
    {
        [$dispatcher, $driver] = $this->pair();
        ArmShiftBreakdown::create([
            'graph_code' => '01',
            'route_code' => '25',
            'shift_code' => '2',
            'sequence' => '1',
            'start_hours' => 14.00,
            'end_hours' => 22.00,
            'hours_total' => 8,
        ]);
        ArmShiftBreakdown::create([
            'graph_code' => '01',
            'route_code' => '25',
            'shift_code' => '3',
            'sequence' => '1',
            'start_hours' => 6.00,
            'end_hours' => 14.00,
            'hours_total' => 8,
        ]);
        NaryadAssignment::create([
            'user_id' => $driver->id,
            'plan_date' => '2026-03-10',
            'route_number' => '25 (2-ранняя)',
            'shift_code' => '2',
        ]);

        $response = $this->actingAs($dispatcher)
            ->postJson('/naryad/assign', [
                'user_id' => $driver->id,
                'plan_date' => '2026-03-11',
                'route_number' => '25 (3-вечёрка)',
            ]);
        $response->assertStatus(422);
        $this->assertStringContainsString('Интервал', (string) $response->json('message'));
    }

    public function test_day_adjustment_must_match_route(): void
    {
        [$dispatcher, $driver] = $this->pair();
        ArmDayAdjustment::create([
            'user_id' => $driver->id,
            'tab_number' => '1001',
            'plan_date' => '2026-03-10',
            'route_code' => '19',
            'shift_code' => '2',
        ]);

        $response = $this->actingAs($dispatcher)
            ->postJson('/naryad/assign', [
                'user_id' => $driver->id,
                'plan_date' => '2026-03-10',
                'route_number' => '25 (2-ранняя)',
            ]);
        $response->assertStatus(422);
        $this->assertStringContainsString('подстройке', (string) $response->json('message'));
    }

    public function test_matching_adjustment_is_allowed(): void
    {
        [$dispatcher, $driver] = $this->pair();
        ArmDayAdjustment::create([
            'user_id' => $driver->id,
            'tab_number' => '1001',
            'plan_date' => '2026-03-10',
            'route_code' => '25',
            'shift_code' => '2',
        ]);

        $this->actingAs($dispatcher)
            ->postJson('/naryad/assign', [
                'user_id' => $driver->id,
                'plan_date' => '2026-03-10',
                'route_number' => '25 (2-ранняя)',
            ])
            ->assertOk();
    }

    /**
     * @return array{0: User, 1: User}
     */
    private function pair(): array
    {
        $dispatcher = User::factory()->dispatcher()->create();
        $driver = User::factory()->driver()->create();
        ArmPersonnel::create([
            'user_id' => $driver->id,
            'tab_number' => '1001',
            'full_name' => 'ТЕСТ',
        ]);

        return [$dispatcher, $driver];
    }
}
