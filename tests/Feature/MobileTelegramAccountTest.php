<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ClickHouseService;
use App\Services\TelegramAccountLinker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class MobileTelegramAccountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        ClickHouseService::fake();
        Cache::flush();
        config(['services.telegram.key_bot_token' => 'test-token', 'services.telegram.key_bot_username' => 'arm_key_bot']);
    }

    public function test_guest_cannot_read_issue_or_unlink(): void
    {
        $this->getJson('/api/mobile/account/telegram')->assertUnauthorized();
        $this->postJson('/api/mobile/account/telegram')->assertUnauthorized();
        $this->deleteJson('/api/mobile/account/telegram')->assertUnauthorized();
    }

    public function test_bearer_user_can_link_without_web_session_and_unlink(): void
    {
        $user = User::factory()->create();
        $this->withToken($user->createToken('mobile')->plainTextToken)
            ->getJson('/api/mobile/account/telegram')->assertOk()->assertJsonPath('linked', false)
            ->assertHeader('Cache-Control', 'no-store, private');
        $url = $this->postJson('/api/mobile/account/telegram', ['user_id' => 999])->assertOk()
            ->assertJsonPath('expires_in', 600)->assertHeader('Cache-Control', 'no-store, private')->json('url');
        $this->assertStringStartsWith('https://t.me/arm_key_bot?start=', $url);
        $token = substr($url, strrpos($url, '=') + 1);
        $this->assertSame($user->id, app(TelegramAccountLinker::class)->consume($token, 123)->id);
        $this->getJson('/api/mobile/account/telegram')->assertOk()->assertJsonPath('linked', true);
        $this->postJson('/api/mobile/account/telegram')->assertConflict();
        $this->deleteJson('/api/mobile/account/telegram')->assertOk()->assertJsonPath('linked', false);
        $this->assertNull(app(TelegramAccountLinker::class)->user(123));
    }

    public function test_status_and_unlink_cannot_affect_another_account(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $links = app(TelegramAccountLinker::class);
        $links->consume($links->issue($owner), 123);
        $this->withToken($other->createToken('mobile')->plainTextToken)
            ->getJson('/api/mobile/account/telegram?user_id='.$owner->id)->assertJsonPath('linked', false);
        $this->deleteJson('/api/mobile/account/telegram', ['user_id' => $owner->id])->assertOk();
        $this->assertSame($owner->id, $links->user(123)->id);
    }

    public function test_blocked_account_cannot_manage_links(): void
    {
        $user = User::factory()->create(['is_active' => false]);
        $this->withToken($user->createToken('mobile')->plainTextToken)
            ->postJson('/api/mobile/account/telegram')->assertForbidden();
        $this->assertDatabaseCount('telegram_link_tokens', 0);
    }

    public function test_unconfigured_bot_and_frequent_requests_do_not_bypass_checks(): void
    {
        $user = User::factory()->create();
        $this->withToken($user->createToken('mobile')->plainTextToken);
        config(['services.telegram.key_bot_username' => 'invalid/name']);
        $this->postJson('/api/mobile/account/telegram')->assertStatus(503);
        config(['services.telegram.key_bot_username' => 'arm_key_bot']);
        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/mobile/account/telegram')->assertOk();
        }
        $this->postJson('/api/mobile/account/telegram')->assertTooManyRequests();
    }
}
