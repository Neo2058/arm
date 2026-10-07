<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckDeviceBinding;
use App\Http\Middleware\CheckDynamicBarrier;
use App\Models\ArmAccount;
use App\Models\ArmAppointment;
use App\Models\ArmMonthNorm;
use App\Models\ArmPayFormula;
use App\Models\ArmPeriod;
use App\Models\ArmPersonnel;
use App\Models\ArmUkCopy;
use App\Models\NaryadAssignment;
use App\Models\NaryadNorm;
use App\Models\User;
use App\Services\Arm\AccountBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ArmStepCTest extends TestCase
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

    public function test_position_change_mid_month_builds_two_accounts_and_lena_clears_dob_on_overtime(): void
    {
        $driver = User::factory()->driver()->create(['name' => 'Смена']);
        ArmPersonnel::create([
            'user_id' => $driver->id,
            'tab_number' => '3210',
            'full_name' => 'СМЕНА',
            'position_code' => 'МШ',
            'class_code' => '1',
        ]);
        ArmMonthNorm::create(['year_month' => '2026-04', 'month_hours' => 100, 'day_hours' => 8]);
        ArmAppointment::create([
            'user_id' => $driver->id,
            'tab_number' => '3210',
            'appointed_on' => '2026-03-01',
            'position_code' => 'П/М',
            'class_code' => '1',
        ]);
        ArmAppointment::create([
            'user_id' => $driver->id,
            'tab_number' => '3210',
            'appointed_on' => '2026-04-16',
            'position_code' => 'МШ',
            'class_code' => '1',
        ]);
        NaryadAssignment::create([
            'user_id' => $driver->id,
            'plan_date' => '2026-04-03',
            'route_number' => '25',
            'shift_code' => '2',
            'hours_line' => 80,
            'hours_holiday' => 4,
        ]);
        NaryadAssignment::create([
            'user_id' => $driver->id,
            'plan_date' => '2026-04-20',
            'route_number' => '12',
            'shift_code' => '2',
            'hours_line' => 80,
            'hours_holiday' => 2,
        ]);
        ArmPayFormula::create([
            'kind' => 'machinist',
            'nom' => 1,
            'name' => 'Основные',
            'percent' => 100,
            'pay_code' => '004',
            'tariff' => 10,
            'formula' => 'Vchas1+dob1',
            'selected' => true,
        ]);
        ArmPayFormula::create([
            'kind' => 'assistant',
            'nom' => 1,
            'name' => 'Основные П/М',
            'percent' => 100,
            'pay_code' => '004',
            'tariff' => 10,
            'formula' => 'Vchas1+dob1',
            'selected' => true,
        ]);

        $built = app(AccountBuilder::class)->buildAccountsForUser($driver->id, '2026-04');
        $this->assertCount(2, $built);
        $first = $built->firstWhere('ls_number', '0');
        $second = $built->firstWhere('ls_number', '1');
        $this->assertSame('П/М', $first->position_code);
        $this->assertSame('МШ', $second->position_code);
        $this->assertSame('primary', $first->kind);
        $this->assertEquals(80.0, $first->totals['vchas1']);
        $this->assertEquals(80.0, $second->totals['vchas1']);
        $this->assertEquals(0.0, $first->totals['dob1']);
        $this->assertEquals(0.0, $second->totals['dob1']);
        $this->assertGreaterThan(0, $second->totals['per1']);
    }

    public function test_repeat_kind_does_not_replace_primary(): void
    {
        $driver = User::factory()->driver()->create();
        ArmPersonnel::create([
            'user_id' => $driver->id,
            'tab_number' => '1111',
            'full_name' => 'ПОВТ',
            'position_code' => 'МШ',
        ]);
        NaryadAssignment::create([
            'user_id' => $driver->id,
            'plan_date' => '2026-04-03',
            'route_number' => '25',
            'shift_code' => '2',
            'hours_line' => 8,
        ]);
        ArmPayFormula::create([
            'kind' => 'machinist',
            'nom' => 1,
            'name' => 'Основные',
            'percent' => 100,
            'pay_code' => '004',
            'tariff' => 10,
            'formula' => 'Vchas1',
            'selected' => true,
        ]);

        $builder = app(AccountBuilder::class);
        $builder->buildMonth('2026-04', 'primary');
        $builder->buildMonth('2026-04', 'repeat');

        $this->assertSame(1, ArmAccount::query()->where('kind', 'primary')->count());
        $this->assertSame(1, ArmAccount::query()->where('kind', 'repeat')->count());
        $repeat = ArmAccount::query()->where('kind', 'repeat')->first();
        $this->assertSame('1', $repeat->ls_number);
        $this->assertSame('1', $repeat->lines()->first()->ls_number);
    }

    public function test_operator_registers_route_sheet_and_closed_month_blocks(): void
    {
        $operator = User::factory()->operator()->create();
        $driver = User::factory()->driver()->create();
        ArmPersonnel::create([
            'user_id' => $driver->id,
            'tab_number' => '2222',
            'full_name' => 'ЛИСТ',
            'position_code' => 'МШ',
            'hired_on' => '2020-01-01',
        ]);

        $this->actingAs($operator)->get('/uchet/sheets?date=2026-04-10')->assertOk();
        $this->actingAs($operator)->putJson('/uchet/sheets/'.$driver->id.'?date=2026-04-10', [
            'registered' => true,
            'work_code' => '25',
            'shift_code' => '2',
        ])->assertOk();

        $row = NaryadAssignment::query()->where('user_id', $driver->id)->first();
        $this->assertTrue((bool) $row->registered);
        $this->assertSame('25', $row->work_code);
        $this->assertSame('2', $row->shift_code);

        ArmPeriod::query()->updateOrCreate(
            ['year_month' => '2026-04'],
            ['status' => 'closed']
        );
        $this->actingAs($operator)->putJson('/uchet/sheets/'.$driver->id.'?date=2026-04-10', [
            'registered' => false,
        ])->assertStatus(422);
    }

    public function test_close_writes_uk_copies_and_archive_opens(): void
    {
        $dispatcher = User::factory()->dispatcher()->create();
        $driver = User::factory()->driver()->create(['name' => 'Архив']);
        ArmPersonnel::create([
            'user_id' => $driver->id,
            'tab_number' => '3333',
            'full_name' => 'АРХИВ',
            'position_code' => 'МШ',
        ]);
        NaryadAssignment::create([
            'user_id' => $driver->id,
            'plan_date' => '2026-05-04',
            'route_number' => '08',
            'work_code' => '08',
            'shift_code' => '2',
            'hours_line' => 7.5,
            'hours_night' => 1.2,
            'registered' => true,
        ]);

        $this->actingAs($dispatcher)->postJson('/uchet/close', ['month' => '2026-05'])->assertOk();
        $this->assertSame(1, ArmUkCopy::query()->count());
        $copy = ArmUkCopy::first();
        $this->assertSame('2026-05', $copy->year_month);
        $this->assertEquals(7.5, $copy->hours_line);
        $this->assertTrue((bool) $copy->registered);

        $this->actingAs($dispatcher)->get('/uchet/archive?month=2026-05')->assertOk();
        $this->actingAs($dispatcher)->get('/uchet/archive/'.$driver->id.'?month=2026-05')->assertOk();
    }
}
