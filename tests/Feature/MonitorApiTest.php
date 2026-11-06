<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class MonitorApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_only_active_super_admin_can_login_and_main_token_is_preserved(): void
    {
        foreach (['driver', 'admin', 'instructor'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->postJson('/api/monitor/login', ['email' => $user->email, 'password' => 'password'])->assertStatus(422);
            $this->assertEquals(0, $user->tokens()->count());
        }
        $user = User::factory()->superAdmin()->create();
        $main = $user->createToken('mobile');
        $response = $this->postJson('/api/monitor/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
        $this->assertTrue($user->tokens()->whereKey($main->accessToken->id)->exists());
        $token = $user->tokens()->where('name', 'monitor')->firstOrFail();
        $this->assertEquals(['monitor:read'], $token->abilities);
        $this->assertNotNull($token->expires_at);
        $this->withToken($response->json('token'))->getJson('/api/mobile/me')->assertForbidden();
    }

    public function test_unauthenticated_and_other_roles_cannot_download_or_read_metrics(): void
    {
        $this->getJson('/api/monitor/snapshot')->assertUnauthorized();
        $this->getJson('/api/monitor/installer')->assertUnauthorized();
        $user = User::factory()->admin()->create();
        $this->withToken($user->createToken('mobile')->plainTextToken)->getJson('/api/monitor/installer')->assertForbidden();
        $this->withToken($user->createToken('monitor', ['monitor:read'])->plainTextToken)->getJson('/api/monitor/snapshot')->assertForbidden();
    }

    public function test_snapshot_filters_secrets_and_exposes_stale_state(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'monitor-test');
        config(['monitor.metrics_path' => $path]);
        file_put_contents($path, "arm_host_cpu_percent 42\narm_collector_timestamp_seconds ".(time() - 300)."\nsecret_token 123\narm_container_up{service=\"arm-app\"} 1\n");
        try {
            $user = User::factory()->superAdmin()->create();
            $token = $user->createToken('monitor', ['monitor:read'])->plainTextToken;
            $this->withToken($token)->getJson('/api/monitor/snapshot')->assertOk()->assertJsonPath('stale', true)->assertJsonCount(3, 'metrics')->assertHeader('Cache-Control', 'no-store, private');
            $user->update(['role' => 'admin']);
            $this->app['auth']->forgetGuards();
            $this->withToken($token)->getJson('/api/monitor/snapshot')->assertForbidden();
        } finally {
            unlink($path);
        }
    }

    public function test_private_installer_requires_main_token_and_active_role(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'installer-test');
        file_put_contents($path, 'private test apk');
        config(['monitor.installer_path' => $path]);
        try {
            $user = User::factory()->superAdmin()->create();
            $this->withToken($user->createToken('monitor', ['monitor:read'])->plainTextToken)->getJson('/api/monitor/installer')->assertForbidden();
            $this->app['auth']->forgetGuards();
            $token = $user->createToken('mobile')->plainTextToken;
            $this->withToken($token)->getJson('/api/monitor/installer')->assertOk()->assertDownload('tch15-monitor.apk');
            $this->withToken($token)->getJson('/api/monitor/installer-info')->assertOk()->assertJsonPath('sha256', hash_file('sha256', $path));
            $user->update(['is_active' => false]);
            $this->app['auth']->forgetGuards();
            $this->withToken($token)->getJson('/api/monitor/installer')->assertUnauthorized();
        } finally {
            unlink($path);
        }
    }

    public function test_expired_tokens_and_missing_metrics_are_not_reported_as_healthy(): void
    {
        $user = User::factory()->superAdmin()->create();
        $expired = $user->createToken('monitor', ['monitor:read'], now()->subMinute());
        $this->withToken($expired->plainTextToken)->getJson('/api/monitor/snapshot')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        config(['monitor.metrics_path' => '/missing/arm.prom']);
        $token = $user->createToken('monitor', ['monitor:read'])->plainTextToken;
        $this->withToken($token)->getJson('/api/monitor/snapshot')->assertStatus(503);
        $this->withToken($token)->postJson('/api/monitor/logout')->assertOk();
        $this->assertNull(PersonalAccessToken::findToken($token));
    }
}
