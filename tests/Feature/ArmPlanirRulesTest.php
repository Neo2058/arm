<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckDeviceBinding;
use App\Http\Middleware\CheckDynamicBarrier;
use App\Models\ArmAbsence;
use App\Models\ArmAppointment;
use App\Models\ArmDayAdjustment;
use App\Models\ArmHoliday;
use App\Models\ArmMonthNorm;
use App\Models\ArmPersonnel;
use App\Models\ArmShiftBreakdown;
use App\Models\DeviationsCatalog;
use App\Models\NaryadAssignment;
use App\Models\NaryadNorm;
use App\Models\RoutesCatalog;
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

    public function test_formula_interval_blocks_13h_rest_after_8h_shift(): void
    {
        [$dispatcher, $driver] = $this->pair();
        $this->breakdown('25', '2', 14.00, 22.00, 8);
        $this->breakdown('25', '3', 11.00, 19.00, 8);
        NaryadAssignment::create([
            'user_id' => $driver->id,
            'plan_date' => '2026-03-10',
            'route_number' => '25 (2-ранняя)',
            'shift_code' => '2',
            'hours_total' => 8,
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

    public function test_formula_interval_allows_double_hours_rest(): void
    {
        [$dispatcher, $driver] = $this->pair();
        $this->breakdown('25', '2', 6.00, 14.00, 8);
        NaryadAssignment::create([
            'user_id' => $driver->id,
            'plan_date' => '2026-03-10',
            'route_number' => '25 (2-ранняя)',
            'shift_code' => '2',
            'hours_total' => 8,
        ]);

        $this->actingAs($dispatcher)
            ->postJson('/naryad/assign', [
                'user_id' => $driver->id,
                'plan_date' => '2026-03-11',
                'route_number' => '25 (2-ранняя)',
            ])
            ->assertOk();
    }

    public function test_nrchas_working_day_ceiling_blocks(): void
    {
        [$dispatcher, $driver] = $this->pair();
        NaryadNorm::query()->first()->update(['planir' => ['norm_extra_hours' => 0]]);
        ArmMonthNorm::create([
            'year_month' => '2026-03',
            'month_hours' => 20,
            'day_hours' => 1,
        ]);
        $this->breakdown('25', '2', 9.00, 18.00, 9);

        $response = $this->actingAs($dispatcher)
            ->postJson('/naryad/assign', [
                'user_id' => $driver->id,
                'plan_date' => '2026-03-10',
                'route_number' => '25 (2-ранняя)',
            ]);
        $response->assertStatus(422);
        $this->assertStringContainsString('NRCHAS', (string) $response->json('message'));
    }

    public function test_kolkint_blocks_fourth_short_interval(): void
    {
        [$dispatcher, $driver] = $this->pair();
        NaryadNorm::query()->first()->update([
            'planir' => [
                'short_interval_hours' => 16.5,
                'max_short_intervals' => 3,
            ],
        ]);
        $this->breakdown('25', '2', 9.00, 17.00, 8);
        foreach (['2026-03-02', '2026-03-03', '2026-03-04', '2026-03-05'] as $day) {
            NaryadAssignment::create([
                'user_id' => $driver->id,
                'plan_date' => $day,
                'route_number' => '25 (2-ранняя)',
                'shift_code' => '2',
                'hours_total' => 8,
            ]);
        }

        $response = $this->actingAs($dispatcher)
            ->postJson('/naryad/assign', [
                'user_id' => $driver->id,
                'plan_date' => '2026-03-06',
                'route_number' => '25 (2-ранняя)',
            ]);
        $response->assertStatus(422);
        $this->assertStringContainsString('межсменных интервалов', (string) $response->json('message'));
    }

    public function test_line_mode_one_blocks_second_person_on_same_shift(): void
    {
        [$dispatcher, $driver] = $this->pair();
        $other = User::factory()->driver()->create();
        ArmPersonnel::create(['user_id' => $other->id, 'tab_number' => '1002', 'full_name' => 'ДРУГОЙ']);
        $this->actingAs($dispatcher)
            ->postJson('/naryad/assign', [
                'user_id' => $driver->id,
                'plan_date' => '2026-03-10',
                'route_number' => '25 (2-ранняя)',
            ])
            ->assertOk();

        $response = $this->actingAs($dispatcher)
            ->postJson('/naryad/assign', [
                'user_id' => $other->id,
                'plan_date' => '2026-03-10',
                'route_number' => '25 (2-ранняя)',
            ]);
        $response->assertStatus(422);
        $this->assertStringContainsString('уже назначено', (string) $response->json('message'));
    }

    public function test_line_mode_two_blocks_inexperienced_pair(): void
    {
        [$dispatcher, $driver] = $this->pair();
        NaryadNorm::query()->first()->update(['planir' => ['line_mode' => 2]]);
        $other = User::factory()->driver()->create();
        ArmPersonnel::create(['user_id' => $other->id, 'tab_number' => '1002', 'full_name' => 'ДРУГОЙ']);
        foreach ([$driver, $other] as $i => $user) {
            ArmAppointment::create([
                'user_id' => $user->id,
                'tab_number' => '100'.($i + 1),
                'appointed_on' => '2026-02-01',
                'position_code' => 'МШ',
                'class_code' => '1',
            ]);
        }
        $this->actingAs($dispatcher)
            ->postJson('/naryad/assign', [
                'user_id' => $driver->id,
                'plan_date' => '2026-03-10',
                'route_number' => '25 (2-ранняя)',
            ])
            ->assertOk();

        $response = $this->actingAs($dispatcher)
            ->postJson('/naryad/assign', [
                'user_id' => $other->id,
                'plan_date' => '2026-03-10',
                'route_number' => '25 (2-ранняя)',
            ]);
        $response->assertStatus(422);
        $this->assertStringContainsString('стажем', (string) $response->json('message'));
    }

    public function test_night_continuation_is_validated(): void
    {
        [$dispatcher, $driver] = $this->pair();
        RoutesCatalog::create([
            'route_number' => '10',
            'from_night' => 'МЗ-1',
            'shift_type' => '4+',
            'start_location' => 'А',
            'default_start_time' => '22:00:00',
            'end_location' => 'Б',
            'default_end_time' => '06:00:00',
        ]);
        ArmAbsence::create([
            'user_id' => $driver->id,
            'tab_number' => '1001',
            'starts_on' => '2026-03-11',
            'ends_on' => '2026-03-11',
            'kind_code' => 'Б',
        ]);

        $response = $this->actingAs($dispatcher)
            ->postJson('/naryad/assign', [
                'user_id' => $driver->id,
                'plan_date' => '2026-03-10',
                'route_number' => '10 (4+-ночь)',
            ]);
        $response->assertStatus(422);
        $this->assertStringContainsString('Продолжение с ночи', (string) $response->json('message'));
        $this->assertFalse(
            NaryadAssignment::query()->where('user_id', $driver->id)->exists()
        );
    }

    public function test_holiday_hours_written_on_assign(): void
    {
        [$dispatcher, $driver] = $this->pair();
        ArmHoliday::create(['holiday_date' => '2026-03-10', 'name' => 'Тест']);
        ArmShiftBreakdown::create([
            'graph_code' => '01',
            'route_code' => '25',
            'shift_code' => '2',
            'sequence' => '1',
            'start_hours' => 9.00,
            'end_hours' => 17.00,
            'hours_total' => 8,
            'hours_line' => 6,
            'hours_reserve' => 1,
        ]);

        $this->actingAs($dispatcher)
            ->postJson('/naryad/assign', [
                'user_id' => $driver->id,
                'plan_date' => '2026-03-10',
                'route_number' => '25 (2-ранняя)',
            ])
            ->assertOk();

        $row = NaryadAssignment::first();
        $this->assertEquals(6, $row->hours_holiday);
        $this->assertEquals(1, $row->hours_holiday_reserve);
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

    private function breakdown(string $route, string $shift, float $start, float $end, float $hours): void
    {
        ArmShiftBreakdown::create([
            'graph_code' => '01',
            'route_code' => $route,
            'shift_code' => $shift,
            'sequence' => '1',
            'start_hours' => $start,
            'end_hours' => $end,
            'hours_total' => $hours,
        ]);
    }
}
