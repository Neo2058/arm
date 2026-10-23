<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyCsrfToken;
use App\Models\User;
use App\Jobs\SendTelegramNotification;
use App\Services\ClickHouseService;
use App\Services\TelegramAccountLinker;
use App\Services\TelegramPasswordReset;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TelegramPasswordRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        ClickHouseService::fake();
        Queue::fake();
        config(['app.url' => 'https://arm.example', 'services.telegram.key_bot_token' => 'test-token',
            'services.telegram.key_webhook_secret' => str_repeat('s', 48)]);
    }

    private function linkedUser(): User
    {
        $user = User::factory()->create();
        $links = app(TelegramAccountLinker::class);
        $links->consume($links->issue($user), 123);

        return $user;
    }

    private function bot(string $text, int $update = 1, string $type = 'private')
    {
        return $this->withHeader('X-Telegram-Bot-Api-Secret-Token', str_repeat('s', 48))
            ->postJson('/telegram/webhook', ['update_id' => $update, 'message' => [
                'from' => ['id' => 123, 'is_bot' => false],
                'chat' => ['id' => 123, 'type' => $type], 'text' => $text,
            ]]);
    }

    private function payload(string $token): array
    {
        return ['token' => $token, 'password' => 'New-strong-password-984', 'password_confirmation' => 'New-strong-password-984'];
    }

    public function test_bot_issues_private_link_only_to_linked_active_user(): void
    {
        $this->bot('/reset')->assertOk()->assertJsonPath('protect_content', true);
        $this->assertDatabaseCount('telegram_password_resets', 0);
        $user = $this->linkedUser();
        $this->bot('/reset', 2, 'supergroup')->assertOk();
        $this->assertDatabaseCount('telegram_password_resets', 0);
        $reply = $this->bot('/start recover', 3)->assertOk()->json('text');
        $this->assertMatchesRegularExpression('~https://arm.example/password/reset#[A-Za-z0-9]{64}~', $reply);
        preg_match('/#([A-Za-z0-9]{64})/', $reply, $match);
        $this->assertDatabaseHas('telegram_password_resets', ['token_hash' => hash('sha256', $match[1])]);
        $this->assertStringNotContainsString($match[1], json_encode(ClickHouseService::recorded()));
        $this->bot('/reset', 4)->assertOk();
        $this->assertDatabaseHas('telegram_password_resets', ['token_hash' => hash('sha256', $match[1])]);
        $user->update(['is_active' => false]);
        $this->travel(2)->minutes();
        $this->assertNull(app(TelegramPasswordReset::class)->issue(123));
    }

    public function test_reset_changes_password_revokes_tokens_and_is_single_use(): void
    {
        config(['services.telegram.bot_token' => 'alerts-test-token', 'services.telegram.chat_id' => '-10012345']);
        $user = $this->linkedUser();
        $other = User::factory()->create();
        $user->createToken('mobile');
        $other->createToken('mobile');
        $oldRemember = $user->remember_token;
        $token = app(TelegramPasswordReset::class)->issue(123);
        $this->get('/password/reset')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertDatabaseCount('telegram_password_resets', 1);
        $this->post('/password/reset', $this->payload($token))->assertOk()->assertSee('Пароль изменён');
        Queue::assertPushed(SendTelegramNotification::class, fn ($job) => str_contains($job->message, 'Восстановление пароля')
            && ! str_contains($job->message, $token) && ! str_contains($job->message, 'New-strong-password-984'));
        $this->assertTrue(Hash::check('New-strong-password-984', $user->fresh()->password));
        $this->assertNotSame($oldRemember, $user->fresh()->remember_token);
        $this->assertSame(0, $user->tokens()->count());
        $this->assertSame(1, $other->tokens()->count());
        $this->assertDatabaseCount('telegram_password_resets', 0);
        $this->post('/password/reset', $this->payload($token))->assertStatus(422);
        $audit = collect(ClickHouseService::recorded())->whereIn('action_type', ['password_reset_requested', 'password_reset_completed'])->values();
        $this->assertSame(['password_reset_requested', 'password_reset_completed'], $audit->pluck('action_type')->all());
        foreach ($audit as $event) {
            $this->assertSame($user->id, $event['user_id']);
            $this->assertSame(['channel' => 'telegram'], json_decode($event['details'], true));
        }
        $this->assertStringNotContainsString($token, $audit->toJson());
        $this->assertStringNotContainsString('New-strong-password-984', $audit->toJson());
    }

    public function test_expired_reissued_and_unlinked_tokens_cannot_reset_password(): void
    {
        $user = $this->linkedUser();
        $resets = app(TelegramPasswordReset::class);
        $old = $resets->issue(123);
        $this->travel(61)->seconds();
        $new = $resets->issue(123);
        $this->assertNull($resets->reset($old, 'Password-984123'));
        $this->travel(11)->minutes();
        $this->assertNull($resets->reset($new, 'Password-984123'));
        $new = $resets->issue(123);
        app(TelegramAccountLinker::class)->unlink($user);
        $links = app(TelegramAccountLinker::class);
        $links->consume($links->issue($user), 123);
        $this->assertNull($resets->reset($new, 'Password-984123'));
    }

    public function test_blocking_user_and_invalid_password_preserve_password(): void
    {
        $user = $this->linkedUser();
        $token = app(TelegramPasswordReset::class)->issue(123);
        $this->post('/password/reset', ['token' => $token, 'password' => 'short', 'password_confirmation' => 'wrong'])
            ->assertStatus(422)->assertSessionMissing('_old_input');
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
        $user->update(['is_active' => false]);
        $this->post('/password/reset', $this->payload($token))->assertStatus(422);
    }

    public function test_previous_web_session_cannot_be_used_after_reset(): void
    {
        $user = $this->linkedUser();
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect();
        $token = app(TelegramPasswordReset::class)->issue(123);
        $this->assertNotNull(app(TelegramPasswordReset::class)->reset($token, 'Password-984123'));
        Auth::forgetGuards();
        $this->get('/account/telegram')->assertRedirect('/login');
    }

    public function test_reset_post_requires_csrf_and_uses_configured_host(): void
    {
        $this->app->bind(VerifyCsrfToken::class, fn ($app) => new class($app, $app->make(Encrypter::class)) extends VerifyCsrfToken {
            protected function runningUnitTests() { return false; }
        });
        $this->post('/password/reset', $this->payload(str_repeat('a', 64)))->assertStatus(419);
        $resets = app(TelegramPasswordReset::class);
        config(['app.url' => 'https://xn---15-7edz.xn--p1ai']);
        $this->assertSame('https://www.xn---15-7edz.xn--p1ai/password/reset#abc', $resets->url('abc'));
    }

    public function test_database_sessions_are_removed_only_for_reset_user(): void
    {
        $user = $this->linkedUser();
        $other = User::factory()->create();
        foreach ([$user, $other] as $owner) {
            DB::table('sessions')->insert(['id' => 'test-'.$owner->id, 'user_id' => $owner->id,
                'payload' => '', 'last_activity' => time()]);
        }
        config(['session.driver' => 'database']);
        $service = app(TelegramPasswordReset::class);
        $this->assertNotNull($service->reset($service->issue(123), 'Password-984123'));
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
        $this->assertDatabaseHas('sessions', ['user_id' => $other->id]);
    }

    public function test_revoked_mobile_token_cannot_access_profile(): void
    {
        $user = $this->linkedUser();
        $token = $user->createToken('mobile')->plainTextToken;
        $this->withToken($token)->getJson('/api/mobile/me')->assertOk();
        $resets = app(TelegramPasswordReset::class);
        $resets->reset($resets->issue(123), 'Password-984123');
        Auth::forgetGuards();
        $this->withToken($token)->getJson('/api/mobile/me')->assertUnauthorized();
    }

    public function test_invalid_input_never_throws_or_flashes_credentials_and_is_throttled(): void
    {
        $this->post('/password/reset', ['token' => [], 'password' => [], 'password_confirmation' => []])
            ->assertStatus(422)->assertSessionMissing('_old_input');
        $long = str_repeat('Я', 40).'1234';
        $this->post('/password/reset', ['token' => str_repeat('a', 64), 'password' => $long, 'password_confirmation' => $long])
            ->assertStatus(422);
        for ($i = 0; $i < 8; $i++) {
            $this->post('/password/reset', $this->payload(str_repeat('a', 64)))->assertStatus(422);
        }
        $this->post('/password/reset', $this->payload(str_repeat('a', 64)))->assertStatus(429);
    }
}
