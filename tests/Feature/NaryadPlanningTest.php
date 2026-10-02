<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckDeviceBinding;
use App\Http\Middleware\CheckDynamicBarrier;
use App\Models\NaryadAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class NaryadPlanningTest extends TestCase
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
    }

    public function test_named_routes_still_resolve_after_split(): void
    {
        $expected = [
            'naryad.index',
            'naryad.partial.setka',
            'naryad.partial.print',
            'naryad.print',
            'naryad.partial.crews',
            'naryad.partial.variants',
            'naryad.partial.calendar',
            'naryad.partial.types',
            'naryad.partial.users',
            'naryad.partial.deviations',
            'naryad.partial.norms',
            'naryad.assign',
            'naryad.unassign',
            'naryad.podstroika-limit.save',
            'naryad.crews.store',
            'naryad.types.store',
            'naryad.types.update',
            'naryad.types.destroy',
            'naryad.variants.store',
            'naryad.deviations.store',
            'naryad.calendar.save',
            'naryad.norms.update',
            'naryad.extra_conditions.store',
            'naryad.user-profile.update-flags',
            'naryad.partial.breakdowns',
            'naryad.partial.holidays',
            'naryad.breakdowns.update',
            'naryad.holidays.store',
            'naryad.holidays.destroy',
            'naryad.partial.personnel',
            'naryad.partial.appointments',
            'naryad.partial.absences',
            'naryad.appointments.store',
            'naryad.appointments.destroy',
            'naryad.absences.store',
            'naryad.absences.destroy',
        ];

        foreach ($expected as $name) {
            $this->assertTrue(Route::has($name), $name);
        }
    }

    public function test_driver_cannot_open_naryad_partials_or_assign(): void
    {
        $driver = User::factory()->driver()->create();

        $this->actingAs($driver)->get('/naryad')->assertForbidden();
        $this->actingAs($driver)->get('/naryad/partial/setka')->assertForbidden();
        $this->actingAs($driver)->get('/naryad/partial/print')->assertForbidden();
        $this->actingAs($driver)->get('/naryad/print')->assertForbidden();
        $this->actingAs($driver)->get('/naryad/partial/crews')->assertForbidden();
        $this->actingAs($driver)->get('/naryad/partial/types')->assertForbidden();
        $this->actingAs($driver)->post('/naryad/assign', [
            'user_id' => $driver->id,
            'plan_date' => now()->toDateString(),
            'route_number' => '25',
        ])->assertForbidden();
    }

    public function test_dispatcher_can_open_shell_and_catalog_partials(): void
    {
        $dispatcher = User::factory()->dispatcher()->create();

        $this->actingAs($dispatcher)->get('/naryad')->assertOk();
        $this->actingAs($dispatcher)->get('/naryad/partial/setka')->assertOk();
        $this->actingAs($dispatcher)->get('/naryad/partial/crews')->assertOk();
        $this->actingAs($dispatcher)->get('/naryad/partial/variants')->assertOk();
        $this->actingAs($dispatcher)->get('/naryad/partial/calendar')->assertOk();
        $this->actingAs($dispatcher)->get('/naryad/partial/types')->assertOk();
        $this->actingAs($dispatcher)->get('/naryad/partial/users')->assertOk();
        $this->actingAs($dispatcher)->get('/naryad/partial/deviations')->assertOk();
        $this->actingAs($dispatcher)->get('/naryad/partial/norms')->assertOk();
        $this->actingAs($dispatcher)->get('/naryad/partial/print')->assertOk();
    }

    public function test_dispatcher_can_assign_and_unassign_route(): void
    {
        $dispatcher = User::factory()->dispatcher()->create();
        $driver = User::factory()->driver()->create();
        $date = now()->toDateString();

        $this->actingAs($dispatcher)
            ->postJson('/naryad/assign', [
                'user_id' => $driver->id,
                'plan_date' => $date,
                'route_number' => '25',
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertTrue(
            NaryadAssignment::where('user_id', $driver->id)
                ->whereDate('plan_date', $date)
                ->where('route_number', '25')
                ->where('assigned_by', $dispatcher->id)
                ->exists()
        );

        $this->actingAs($dispatcher)
            ->postJson('/naryad/unassign', [
                'user_id' => $driver->id,
                'plan_date' => $date,
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertFalse(
            NaryadAssignment::where('user_id', $driver->id)
                ->whereDate('plan_date', $date)
                ->exists()
        );
    }

    public function test_dispatcher_can_create_schedule_type(): void
    {
        $dispatcher = User::factory()->dispatcher()->create();

        $this->actingAs($dispatcher)
            ->postJson('/naryad/types', [
                'name' => 'Двухсменка',
                'routes_count' => 4,
                'people_per_route' => 2,
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('schedule_types', [
            'name' => 'Двухсменка',
            'routes_count' => 4,
        ]);
    }
}
