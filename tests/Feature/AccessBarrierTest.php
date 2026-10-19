<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserDevice;
use App\Services\ClickHouseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class AccessBarrierTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        ClickHouseService::fake();
        Http::fake();
        Mail::fake();
    }

    public function test_driver_without_device_is_sent_to_register_form(): void
    {
        $driver = User::factory()->driver()->create();

        $this->actingAs($driver)
            ->get('/mainMenu')
            ->assertRedirect(route('device.register.form'));
    }

    public function test_driver_with_unknown_device_key_is_sent_to_register_form(): void
    {
        $driver = User::factory()->driver()->create();

        $this->actingAs($driver)
            ->withUnencryptedCookie('device_key', 'unknown-device')
            ->get('/mainMenu')
            ->assertRedirect(route('device.register.form'));
    }

    public function test_driver_with_unapproved_device_is_sent_to_register_form(): void
    {
        $driver = User::factory()->driver()->create();
        UserDevice::create([
            'user_id' => $driver->id,
            'device_key' => 'pending-key',
            'device_name' => 'Chrome',
            'is_approved' => false,
        ]);

        $this->actingAs($driver)
            ->withUnencryptedCookie('device_key', 'pending-key')
            ->get('/mainMenu')
            ->assertRedirect(route('device.register.form'));
    }

    public function test_approved_device_without_barrier_is_sent_to_barrier(): void
    {
        $driver = $this->driverWithApprovedDevice();

        $this->actingAs($driver)
            ->withUnencryptedCookie('device_key', 'approved-key')
            ->get('/mainMenu')
            ->assertRedirect(route('barrier.show'));
    }

    public function test_approved_device_and_passed_barrier_can_open_main_menu(): void
    {
        $driver = $this->driverWithApprovedDevice();

        $this->actingAs($driver)
            ->withUnencryptedCookie('device_key', 'approved-key')
            ->withSession(['dynamic_barrier_passed' => true])
            ->get('/mainMenu')
            ->assertOk();
    }

    public function test_driver_can_open_device_register_and_barrier_without_loop(): void
    {
        $driver = User::factory()->driver()->create();

        $this->actingAs($driver)
            ->get(route('device.register.form'))
            ->assertOk();

        $approved = $this->driverWithApprovedDevice();

        $this->actingAs($approved)
            ->withUnencryptedCookie('device_key', 'approved-key')
            ->get(route('barrier.show'))
            ->assertOk();
    }

    public function test_driver_can_submit_device_registration_without_passing_barrier(): void
    {
        $driver = User::factory()->driver()->create();

        $this->actingAs($driver)
            ->post(route('device.register.submit'), [
                'device_key' => 'new-device',
                'device_name' => 'Firefox',
            ])
            ->assertRedirect(route('device.register.form'));

        $this->assertDatabaseHas('user_devices', [
            'user_id' => $driver->id,
            'device_key' => 'new-device',
            'is_approved' => false,
        ]);
    }

    public function test_admin_bypasses_device_and_barrier(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get('/mainMenu')
            ->assertOk();
    }

    public function test_filament_panel_does_not_run_device_or_barrier(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get('/admin')
            ->assertOk();

        $this->actingAs(User::factory()->driver()->create())
            ->get('/admin')
            ->assertForbidden();
    }

    public function test_livewire_update_is_not_redirected_to_device_or_barrier(): void
    {
        $driver = User::factory()->driver()->create();

        $this->actingAs($driver);
        $response = $this->postJson('/livewire/update', ['components' => []]);

        $this->assertFalse($response->isRedirect(), (string) $response->headers->get('Location'));
        $this->assertNotSame(route('device.register.form'), $response->headers->get('Location'));
        $this->assertNotSame(route('barrier.show'), $response->headers->get('Location'));
    }

    public function test_admin_file_preview_works_without_device_cookie(): void
    {
        Storage::fake('s3');
        Storage::disk('s3')->put('uploads/teaching/preview.pdf', '%PDF-1.4 preview');

        $url = URL::temporarySignedRoute(
            'admin.documents.serve',
            now()->addMinutes(15),
            ['path' => 'uploads/teaching/preview.pdf'],
        );

        $this->actingAs(User::factory()->admin()->create())
            ->get($url)
            ->assertOk();

        $this->actingAs(User::factory()->driver()->create())
            ->get($url)
            ->assertForbidden();
    }

    private function driverWithApprovedDevice(): User
    {
        $driver = User::factory()->driver()->create();
        UserDevice::create([
            'user_id' => $driver->id,
            'device_key' => 'approved-key',
            'device_name' => 'Chrome',
            'is_approved' => true,
        ]);

        return $driver;
    }
}
