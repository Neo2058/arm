<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckDeviceBinding;
use App\Http\Middleware\CheckDynamicBarrier;
use App\Models\ArmPayFormula;
use App\Models\ArmPeriod;
use App\Models\ArmPersonnel;
use App\Models\ArmShiftBreakdown;
use App\Models\NaryadAssignment;
use App\Models\NaryadNorm;
use App\Models\User;
use App\Services\Arm\AccountBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ArmAccountingTest extends TestCase
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

    public function test_assign_copies_second_person_hours_from_breakdown(): void
    {
        $dispatcher = User::factory()->dispatcher()->create();
        $driver = User::factory()->driver()->create();
        ArmPersonnel::create(['user_id' => $driver->id, 'tab_number' => '2001', 'full_name' => 'ТЕСТ']);
        ArmShiftBreakdown::create([
            'graph_code' => '01',
            'route_code' => '25',
            'shift_code' => '2',
            'sequence' => '1',
            'hours_total' => 8,
            'hours_line' => 6,
            'hours_line_2' => 5.5,
            'hours_night_2' => 1.1,
        ]);

        $this->actingAs($dispatcher)->postJson('/naryad/assign', [
            'user_id' => $driver->id,
            'plan_date' => '2026-04-10',
            'route_number' => '25 (2-ранняя)',
        ])->assertOk();

        $row = NaryadAssignment::first();
        $this->assertTrue((bool) $row->two_person);
        $this->assertEquals(5.5, $row->hours_line_2);
        $this->assertEquals(1.1, $row->hours_night_2);
    }

    public function test_operator_opens_uchet_but_not_naryad(): void
    {
        $operator = User::factory()->operator()->create();
        $this->actingAs($operator)->get('/uchet')->assertOk();
        $this->actingAs($operator)->get('/naryad')->assertForbidden();
        $driver = User::factory()->driver()->create();
        $this->actingAs($driver)->get('/uchet')->assertForbidden();
    }

    public function test_closed_month_blocks_assign(): void
    {
        $dispatcher = User::factory()->dispatcher()->create();
        $driver = User::factory()->driver()->create();
        ArmPeriod::create(['year_month' => '2026-04', 'status' => 'closed']);

        $this->actingAs($dispatcher)->postJson('/naryad/assign', [
            'user_id' => $driver->id,
            'plan_date' => '2026-04-02',
            'route_number' => '25',
        ])->assertStatus(422);
    }

    public function test_builds_account_from_formula_and_exports_lsbuh(): void
    {
        $operator = User::factory()->operator()->create();
        $driver = User::factory()->driver()->create(['name' => 'Иванов']);
        ArmPersonnel::create([
            'user_id' => $driver->id,
            'tab_number' => '0321',
            'full_name' => 'ИВАНОВ',
            'position_code' => 'МШ',
            'depo_code' => 'ТЧ15',
        ]);
        NaryadAssignment::create([
            'user_id' => $driver->id,
            'plan_date' => '2026-04-03',
            'route_number' => '25',
            'hours_line' => 8,
            'hours_night' => 1,
        ]);
        ArmPayFormula::create([
            'kind' => 'machinist',
            'nom' => 1,
            'name' => 'Основные часы 1 лицо',
            'percent' => 100,
            'pay_code' => '004',
            'tariff_code' => 'tm1',
            'tariff' => 176.81,
            'cost_code' => '2000058',
            'formula' => 'Vchas1+dob1',
            'selected' => true,
        ]);

        $result = app(AccountBuilder::class)->buildMonth('2026-04');
        $this->assertSame(1, $result['accounts']);
        $this->assertSame(1, $result['lines']);

        $response = $this->actingAs($operator)->get('/uchet/lsbuh.csv?month=2026-04');
        $response->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringContainsString('GODMES', $csv);
        $this->assertStringContainsString('0321', $csv);
        $this->assertStringContainsString('004', $csv);
        $this->assertStringContainsString('202604', $csv);
    }

    public function test_close_and_reopen_period(): void
    {
        $dispatcher = User::factory()->dispatcher()->create();
        $this->actingAs($dispatcher)->postJson('/uchet/close', ['month' => '2026-05'])->assertOk();
        $this->assertTrue(ArmPeriod::closedMonth('2026-05'));
        $this->actingAs($dispatcher)->postJson('/uchet/reopen', ['month' => '2026-05'])->assertOk();
        $this->assertFalse(ArmPeriod::closedMonth('2026-05'));
    }
}
