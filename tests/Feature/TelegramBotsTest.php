<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyCsrfToken;
use App\Jobs\SendTelegramNotification;
use App\Models\User;
use App\Models\Document;
use App\Services\ClickHouseService;
use App\Services\MonthlyAccessKey;
use App\Services\TelegramAccountLinker;
use App\Services\TelegramBotApi;
use App\Services\TelegramService;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TelegramBotsTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'telegram-webhook-test-secret-1234567890';

    protected function setUp(): void
    {
        parent::setUp();
        ClickHouseService::fake();
        Cache::flush();
        Queue::fake();
        Mail::fake();
        config([
            'services.telegram.key_bot_token' => 'key-test-token',
            'services.telegram.key_bot_username' => 'arm_key_bot',
            'services.telegram.key_webhook_secret' => self::SECRET,
            'services.telegram.bot_token' => 'alerts-test-token',
            'services.telegram.chat_id' => '-10012345',
            'services.telegram.relay_url' => null,
            'services.telegram.key_relay_url' => null,
        ]);
        Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
    }

    private function update(string $text = '/key', int $sender = 123, int $update = 1): array
    {
        return ['update_id' => $update, 'message' => [
            'from' => ['id' => $sender, 'is_bot' => false],
            'chat' => ['id' => $sender, 'type' => 'private'], 'text' => $text,
        ]];
    }

    private function webhook(array $update)
    {
        return $this->withHeader('X-Telegram-Bot-Api-Secret-Token', self::SECRET)
            ->postJson('/telegram/webhook', $update);
    }

    private function link(User $user, int $telegramId = 123): void
    {
        $links = app(TelegramAccountLinker::class);
        $this->assertNotNull($links->consume($links->issue($user), $telegramId));
    }

    public function test_webhook_requires_secret_even_when_user_is_logged_in(): void
    {
        $this->actingAs(User::factory()->admin()->create())->postJson('/telegram/webhook', $this->update())->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_machine_webhook_accepts_secret_without_session_or_csrf(): void
    {
        $this->app->bind(VerifyCsrfToken::class, fn ($app) => new class($app, $app->make(Encrypter::class)) extends VerifyCsrfToken
        {
            protected function runningUnitTests() { return false; }
        });
        $this->webhook($this->update())->assertOk();
        $this->assertGuest();
        Http::assertSent(fn ($r) => str_contains($r->url(), 'key-test-token') && str_contains($r['text'], 'Войдите на сайт'));
    }

    public function test_unregistered_sender_never_receives_key(): void
    {
        $this->webhook($this->update())->assertOk();
        Http::assertSent(fn ($r) => ! str_contains($r['text'], 'Ключ доступа на') && $r['chat_id'] === '123');
    }

    public function test_active_linked_user_receives_the_same_key_as_barrier(): void
    {
        $this->link(User::factory()->create());
        $this->webhook($this->update())->assertOk();
        $key = implode(' ', app(MonthlyAccessKey::class)->sequence());
        Http::assertSent(fn ($r) => str_contains($r->url(), '/botkey-test-token/sendMessage')
            && str_contains($r['text'], $key) && $r['protect_content'] === true);
        $this->assertStringNotContainsString($key, json_encode(ClickHouseService::recorded()));
    }

    public function test_blocked_user_cannot_get_key_with_existing_link(): void
    {
        $user = User::factory()->create();
        $this->link($user);
        DB::table('users')->where('id', $user->id)->update(['is_active' => false]);
        $this->webhook($this->update())->assertOk();
        Http::assertSent(fn ($r) => ! str_contains($r['text'], 'Ключ доступа на'));
    }

    public function test_groups_cannot_get_key_or_consume_link(): void
    {
        $user = User::factory()->create();
        $token = app(TelegramAccountLinker::class)->issue($user);
        $update = $this->update('/start '.$token);
        $update['message']['chat'] = ['id' => -123, 'type' => 'supergroup'];
        $this->webhook($update)->assertOk();
        Http::assertNothingSent();
        $this->assertDatabaseCount('telegram_accounts', 0);
    }

    public function test_start_consumes_single_use_token_and_records_no_plain_token(): void
    {
        $user = User::factory()->create();
        $links = app(TelegramAccountLinker::class);
        $token = $links->issue($user);
        $this->assertDatabaseHas('telegram_link_tokens', ['token_hash' => hash('sha256', $token)]);
        $this->webhook($this->update('/start '.$token))->assertOk();
        $this->assertDatabaseHas('telegram_accounts', ['user_id' => $user->id, 'telegram_user_id' => 123]);
        $this->assertNull($links->consume($token, 456));
        $this->assertStringNotContainsString($token, json_encode(ClickHouseService::recorded()));
    }

    public function test_expired_and_reissued_tokens_are_rejected(): void
    {
        $user = User::factory()->create();
        $links = app(TelegramAccountLinker::class);
        $old = $links->issue($user);
        $new = $links->issue($user);
        $this->assertNull($links->consume($old, 123));
        $this->travel(11)->minutes();
        $this->assertNull($links->consume($new, 123));
    }

    public function test_telegram_identity_cannot_be_reassigned_to_another_user(): void
    {
        $this->link(User::factory()->create());
        $other = User::factory()->create();
        $links = app(TelegramAccountLinker::class);
        $this->assertNull($links->consume($links->issue($other), 123));
        $this->assertDatabaseCount('telegram_accounts', 1);
    }

    public function test_link_page_is_available_before_device_and_barrier_but_requires_login(): void
    {
        $this->get('/account/telegram')->assertRedirect('/login');
        $user = User::factory()->create();
        $this->actingAs($user)->get('/account/telegram')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->post('/account/telegram')->assertRedirect('/account/telegram')->assertSessionHas('telegram_link_url');
        $this->get('/documents')->assertRedirect(route('device.register.form'));
    }

    public function test_unlink_revokes_access_immediately(): void
    {
        $user = User::factory()->create();
        $this->link($user);
        $this->actingAs($user)->delete('/account/telegram')->assertRedirect('/account/telegram');
        $this->assertNull(app(TelegramAccountLinker::class)->user(123));
    }

    public function test_duplicate_telegram_update_is_not_processed_again(): void
    {
        $this->link(User::factory()->create());
        $this->webhook($this->update())->assertOk();
        $this->webhook($this->update())->assertOk();
        Http::assertSentCount(1);
    }

    public function test_sender_rate_limit_does_not_send_more_than_ten_replies_per_minute(): void
    {
        for ($i = 1; $i <= 11; $i++) {
            $this->webhook($this->update('/key', 123, $i))->assertOk();
        }
        Http::assertSentCount(10);
    }

    public function test_delivery_error_returns_retryable_status_without_leaking_token(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['*' => Http::response(['ok' => false], 500)]);
        $this->webhook($this->update())->assertStatus(503)->assertExactJson(['ok' => false]);
    }

    public function test_notifications_use_other_bot_and_configured_queue(): void
    {
        TelegramService::send('Тест уведомлений', null, '');
        Queue::assertPushed(SendTelegramNotification::class, fn ($job) => $job->chatId === '-10012345'
            && $job->queue === 'notifications' && $job->connection === 'database');
        (new SendTelegramNotification('Тест', '-10012345', ''))->handle(app(TelegramBotApi::class));
        Http::assertSent(fn ($r) => str_contains($r->url(), '/botalerts-test-token/sendMessage'));
    }

    public function test_successful_login_queues_notification_without_password(): void
    {
        $user = User::factory()->create();
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect();
        Queue::assertPushed(SendTelegramNotification::class, fn ($job) => str_contains($job->message, 'Вход в ARM')
            && str_contains($job->message, 'ID '.$user->id) && ! str_contains($job->message, 'password'));
    }

    public function test_registration_and_device_requests_queue_administrative_alerts(): void
    {
        $this->from('/about')->post('/about/request', [
            'tab_number' => '45231', 'fio' => 'Иванов Иван Иванович', 'consent' => '1',
        ])->assertSessionHas('success');
        Queue::assertPushed(SendTelegramNotification::class, fn ($job) => str_contains($job->message, 'НОВАЯ ЗАЯВКА НА УЧЁТНУЮ ЗАПИСЬ'));

        $this->actingAs(User::factory()->create())->post('/api/device/register', [
            'device_key' => 'private-device-key', 'device_name' => 'Телефон',
        ])->assertRedirect();
        Queue::assertPushed(SendTelegramNotification::class, fn ($job) => str_contains($job->message, 'ЗАЯВКА НА ПРИВЯЗКУ УСТРОЙСТВА')
            && ! str_contains($job->message, 'private-device-key'));
    }

    public function test_download_violation_queues_alert_and_remains_forbidden(): void
    {
        Storage::fake('s3');
        Storage::disk('s3')->put('test.pdf', '%PDF-1.4 test');
        $document = Document::create(['title' => 'Инструкция', 'file_path' => 'test.pdf', 'category' => 'manual']);
        $this->withoutMiddleware([\App\Http\Middleware\CheckDeviceBinding::class, \App\Http\Middleware\CheckDynamicBarrier::class]);
        $this->actingAs(User::factory()->create())->get('/documents/'.$document->id.'/download')->assertForbidden();
        Queue::assertPushed(SendTelegramNotification::class, fn ($job) => str_contains($job->message, 'Нарушение регламента использования документов'));
    }

    public function test_separate_relays_do_not_mix_bots_or_secrets(): void
    {
        config([
            'services.telegram.relay_url' => 'https://alerts-relay.example',
            'services.telegram.relay_secret' => 'alerts-secret',
            'services.telegram.key_relay_url' => 'https://key-relay.example',
            'services.telegram.key_relay_secret' => 'key-secret',
        ]);
        $api = app(TelegramBotApi::class);
        $api->message('key', '123', 'Личный ответ');
        $api->message('notifications', '-10012345', 'Алерт');
        Http::assertSent(fn ($r) => $r->url() === 'https://key-relay.example' && $r['secret'] === 'key-secret');
        Http::assertSent(fn ($r) => $r->url() === 'https://alerts-relay.example' && $r['secret'] === 'alerts-secret');
    }

    public function test_manage_installs_secret_protected_webhook(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake([
            '*/getMe' => Http::response(['ok' => true, 'result' => ['id' => 1, 'username' => 'arm_key_bot']]),
            '*/setWebhook' => Http::response(['ok' => true, 'result' => true]),
            '*/getWebhookInfo' => Http::response(['ok' => true, 'result' => ['url' => 'https://arm.example/telegram/webhook']]),
        ]);
        config(['services.telegram.key_webhook_secret' => str_repeat('x', 48)]);
        \Illuminate\Support\Facades\URL::forceRootUrl('https://arm.example');
        \Illuminate\Support\Facades\URL::forceScheme('https');
        $this->artisan('telegram:manage', ['bot' => 'key', '--webhook' => true])->assertExitCode(0);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/setWebhook') && $r['secret_token'] === str_repeat('x', 48)
            && $r['allowed_updates'] === ['message']);
    }

    public function test_message_formatting_failure_retries_as_plain_text(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fakeSequence()->push(['ok' => false, 'description' => "can't parse entities"], 400)->push(['ok' => true]);
        app(TelegramBotApi::class)->message('notifications', '1', 'name_with_bad_*', 'Markdown');
        Http::assertSentCount(2);
        Http::assertSent(fn ($r) => ! isset($r['parse_mode']));
    }
}
