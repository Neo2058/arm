<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckDeviceBinding;
use App\Http\Middleware\CheckDynamicBarrier;
use App\Models\Podstroika;
use App\Models\User;
use Filament\Panel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserRoleAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([
            CheckDeviceBinding::class,
            CheckDynamicBarrier::class,
        ]);
    }

    public function test_user_role_helpers(): void
    {
        $dispatcher = User::factory()->dispatcher()->make();
        $admin = User::factory()->admin()->make();
        $super = User::factory()->superAdmin()->make();
        $instructor = User::factory()->instructor()->make();
        $driver = User::factory()->driver()->make();

        $this->assertTrue($dispatcher->isDispatcher());
        $this->assertContains('naryadchik', $dispatcher->roleValuesForAccess());

        $this->assertTrue($admin->isAdmin());
        $this->assertTrue($super->isAdmin());
        $this->assertTrue($super->isSuperAdmin());
        $this->assertFalse($admin->isSuperAdmin());
        $this->assertTrue($admin->canBypassAccessBarriers());
        $this->assertFalse($dispatcher->canBypassAccessBarriers());

        $this->assertTrue($instructor->canViewRospisiStatistics());
        $this->assertTrue($admin->canViewRospisiStatistics());
        $this->assertFalse($driver->canViewRospisiStatistics());
        $this->assertTrue($driver->isDriver());
    }

    public function test_dispatcher_can_access_legacy_naryadchik_allowed_roles(): void
    {
        $dispatcher = User::factory()->dispatcher()->make();

        $this->assertTrue($dispatcher->canAccessByRoles(['naryadchik']));
        $this->assertTrue($dispatcher->canAccessByRoles(['dispatcher']));
        $this->assertFalse($dispatcher->canAccessByRoles(['driver']));
        $this->assertTrue($dispatcher->canAccessByRoles(null));
        $this->assertFalse($dispatcher->canAccessByRoles([]));
    }

    public function test_driver_cannot_open_naryad_planning(): void
    {
        $driver = User::factory()->driver()->create();

        $this->actingAs($driver)
            ->get('/naryad')
            ->assertForbidden();
    }

    public function test_instructor_cannot_open_naryad_planning(): void
    {
        $instructor = User::factory()->instructor()->create();

        $this->actingAs($instructor)
            ->get('/naryad')
            ->assertForbidden();
    }

    public function test_admin_cannot_open_naryad_planning(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get('/naryad')
            ->assertForbidden();
    }

    public function test_dispatcher_can_open_naryad_planning(): void
    {
        $dispatcher = User::factory()->dispatcher()->create();

        $response = $this->actingAs($dispatcher)->get('/naryad');

        $this->assertNotEquals(403, $response->status());
    }

    public function test_driver_cannot_update_podstroika_status(): void
    {
        $driver = User::factory()->driver()->create();
        $podstroika = Podstroika::create([
            'user_id' => $driver->id,
            'for_month' => now()->startOfMonth()->toDateString(),
            'details' => 'Тест',
            'status' => 'pending',
        ]);

        $this->actingAs($driver)
            ->post("/podstroiki/{$podstroika->id}/status", ['status' => 'podstroeno'])
            ->assertForbidden();
    }

    public function test_can_access_panel_only_for_admins(): void
    {
        $panel = $this->createStub(Panel::class);

        $this->assertTrue(User::factory()->admin()->make()->canAccessPanel($panel));
        $this->assertTrue(User::factory()->superAdmin()->make()->canAccessPanel($panel));
        $this->assertFalse(User::factory()->student()->make()->canAccessPanel($panel));
        $this->assertFalse(User::factory()->dispatcher()->make()->canAccessPanel($panel));
        $this->assertFalse(User::factory()->instructor()->make()->canAccessPanel($panel));
    }
}
