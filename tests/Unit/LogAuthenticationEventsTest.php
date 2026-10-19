<?php

namespace Tests\Unit;

use App\Listeners\LogAuthenticationEvents;
use App\Models\User;
use App\Services\ClickHouseService;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class LogAuthenticationEventsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        ClickHouseService::fake();
    }

    public function test_via_detects_mobile_filament_and_web(): void
    {
        $this->app->instance('request', Request::create('/api/mobile/login', 'POST'));
        $this->assertSame('mobile', LogAuthenticationEvents::via());

        $this->app->instance('request', Request::create('/admin/login', 'POST'));
        $this->assertSame('filament', LogAuthenticationEvents::via());

        $this->app->instance('request', Request::create('/login', 'POST'));
        $this->assertSame('web', LogAuthenticationEvents::via());
    }

    public function test_login_event_records_actor_and_channel(): void
    {
        $user = User::factory()->driver()->create();
        $this->app->instance('request', Request::create('/login', 'POST'));

        (new LogAuthenticationEvents)->login(new Login('web', $user, false));

        $row = ClickHouseService::recorded()[0];
        $this->assertSame('login', $row['action_type']);
        $this->assertSame($user->id, $row['user_id']);
        $this->assertSame('driver', $row['user_role']);
        $this->assertStringContainsString('"via":"web"', $row['details']);
    }

    public function test_filament_login_uses_web_action_with_filament_via(): void
    {
        $user = User::factory()->admin()->create();
        $this->app->instance('request', Request::create('/admin/login', 'POST'));

        (new LogAuthenticationEvents)->login(new Login('web', $user, false));

        $row = ClickHouseService::recorded()[0];
        $this->assertSame('login', $row['action_type']);
        $this->assertStringContainsString('"via":"filament"', $row['details']);
    }

    public function test_inactive_login_is_failed_not_success(): void
    {
        $user = User::factory()->driver()->create(['is_active' => false]);
        $this->app->instance('request', Request::create('/api/mobile/login', 'POST'));

        (new LogAuthenticationEvents)->login(new Login('web', $user, false));

        $row = ClickHouseService::recorded()[0];
        $this->assertSame('login_failed', $row['action_type']);
        $this->assertStringContainsString('"reason":"inactive"', $row['details']);
        $this->assertStringNotContainsString('mobile.login', $row['action_type']);
    }

    public function test_failed_login_is_system_event_without_password(): void
    {
        $this->app->instance('request', Request::create('/login', 'POST'));

        (new LogAuthenticationEvents)->failed(new Failed('web', null, [
            'email' => 'nobody@example.com',
            'password' => 'secret-value',
        ]));

        $row = ClickHouseService::recorded()[0];
        $this->assertSame('login_failed', $row['action_type']);
        $this->assertSame(0, $row['user_id']);
        $this->assertSame(ClickHouseService::SYSTEM_ROLE, $row['user_role']);
        $this->assertStringContainsString('"reason":"credentials"', $row['details']);
        $this->assertStringContainsString('nobody@example.com', $row['details']);
        $this->assertStringNotContainsString('secret-value', $row['details']);
    }

    public function test_failed_login_of_inactive_user_uses_inactive_reason(): void
    {
        $user = User::factory()->driver()->create(['is_active' => false]);
        $this->app->instance('request', Request::create('/login', 'POST'));

        (new LogAuthenticationEvents)->failed(new Failed('web', null, [
            'email' => $user->email,
            'password' => 'password',
        ]));

        $row = ClickHouseService::recorded()[0];
        $this->assertSame('login_failed', $row['action_type']);
        $this->assertSame(0, $row['user_id']);
        $this->assertStringContainsString('"reason":"inactive"', $row['details']);
    }

    public function test_logout_keeps_actor_after_session_is_cleared(): void
    {
        $user = User::factory()->driver()->create();
        $this->app->instance('request', Request::create('/logout', 'POST'));

        (new LogAuthenticationEvents)->logout(new Logout('web', $user));

        $row = ClickHouseService::recorded()[0];
        $this->assertSame('logout', $row['action_type']);
        $this->assertSame($user->id, $row['user_id']);
        $this->assertSame('driver', $row['user_role']);
    }

    public function test_logout_of_inactive_user_is_skipped(): void
    {
        $user = User::factory()->driver()->create(['is_active' => false]);
        $this->app->instance('request', Request::create('/api/mobile/login', 'POST'));

        (new LogAuthenticationEvents)->logout(new Logout('web', $user));

        $this->assertSame([], ClickHouseService::recorded());
    }
}
