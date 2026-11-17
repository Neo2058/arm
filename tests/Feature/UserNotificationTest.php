<?php

namespace Tests\Feature;

use App\Enums\ChatMemberRole;
use App\Enums\ChatMemberSource;
use App\Enums\ChatMessageStatus;
use App\Enums\ChatMessageType;
use App\Enums\ChatType;
use App\Enums\UserNotificationType;
use App\Http\Middleware\CheckDeviceBinding;
use App\Http\Middleware\CheckDynamicBarrier;
use App\Models\ChatMember;
use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\DisciplineAssignment;
use App\Models\FormularTask;
use App\Models\PushSubscription;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\UserProfile;
use App\Services\Chat\ChatService;
use App\Services\ClickHouseService;
use App\Services\Discipline\DisciplineService;
use App\Services\Discipline\ReportScanner;
use App\Services\FormularService;
use App\Services\Notifications\UserNotifications;
use App\Services\Notifications\WebPushPing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class UserNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        ClickHouseService::fake();
        $this->withoutMiddleware([CheckDeviceBinding::class, CheckDynamicBarrier::class]);
        config([
            'chat.enabled' => true,
            'chat.pilot_column' => null,
            'chat.timezone' => 'Europe/Moscow',
            'notifications.enabled' => true,
        ]);
        Carbon::setTestNow(Carbon::parse('2026-10-20 12:00:00', 'Europe/Moscow'));
        Storage::fake('s3');
        $this->mock(ReportScanner::class)->shouldReceive('scan')->andReturnNull()->byDefault();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_guest_cannot_read_web_or_mobile_inbox(): void
    {
        $this->get('/notifications')->assertRedirect('/login');
        $this->getJson('/notifications/inbox.json')->assertUnauthorized();
        $this->getJson('/api/mobile/notifications')->assertUnauthorized();
    }

    public function test_me_exposes_notifications_feature(): void
    {
        $user = $this->instructor();
        $this->withToken($user->createToken('mobile')->plainTextToken)
            ->getJson('/api/mobile/me')
            ->assertOk()
            ->assertJsonPath('user.features.notifications', true);
    }

    public function test_chat_text_notifies_other_members_and_coalesces(): void
    {
        $admin = User::factory()->admin()->create();
        $sender = $this->instructor();
        $peer = $this->driver();
        $outsider = $this->driver('16');
        $room = $this->columnRoom($admin, '15', 'Колонна 15');
        $this->member($room, $sender, ChatMemberRole::MODERATOR);
        $this->member($room, $peer);
        $this->member($room, $outsider);

        $this->api($sender, 'post', '/chats/'.$room->id.'/messages', [
            'client_id' => (string) Str::uuid(),
            'type' => 'text',
            'body' => 'Секретный текст сообщения',
        ])->assertCreated();

        $this->assertDatabaseCount('user_notifications', 2);
        $this->assertDatabaseMissing('user_notifications', ['user_id' => $sender->id]);
        $row = UserNotification::query()->where('user_id', $peer->id)->firstOrFail();
        $this->assertSame(UserNotificationType::ChatMessage, $row->type);
        $this->assertSame(1, (int) $row->count);
        $this->assertSame($room->id, (int) $row->resource_id);
        $this->assertStringContainsString('Колонна 15', $row->title);
        $this->assertStringNotContainsString('Секретный текст', $row->title);
        $this->assertNull($row->read_at);

        $this->api($sender, 'post', '/chats/'.$room->id.'/messages', [
            'client_id' => (string) Str::uuid(),
            'type' => 'text',
            'body' => 'Второе',
        ])->assertCreated();

        $this->assertDatabaseCount('user_notifications', 2);
        $this->assertSame(2, (int) $row->fresh()->count);

        $inbox = $this->api($peer, 'get', '/notifications?unread=1')->assertOk();
        $this->assertSame(1, $inbox->json('unread'));
        $this->assertSame('chat_message', $inbox->json('items.0.type'));
        $this->assertStringNotContainsString('Секретный', json_encode($inbox->json()));
        $this->assertStringContainsString('(2)', $inbox->json('items.0.title'));
    }

    public function test_chat_replay_does_not_notify_again(): void
    {
        $admin = User::factory()->admin()->create();
        $sender = $this->instructor();
        $peer = $this->driver();
        $room = $this->columnRoom($admin, '15');
        $this->member($room, $sender, ChatMemberRole::MODERATOR);
        $this->member($room, $peer);
        $clientId = (string) Str::uuid();
        $body = ['client_id' => $clientId, 'type' => 'text', 'body' => 'На смену'];
        $this->api($sender, 'post', '/chats/'.$room->id.'/messages', $body)->assertCreated();
        $this->api($sender, 'post', '/chats/'.$room->id.'/messages', $body)->assertOk();
        $this->assertDatabaseCount('user_notifications', 1);
        $this->assertSame(1, (int) UserNotification::query()->firstOrFail()->count);
    }

    public function test_inactive_and_expired_deputy_are_not_notified(): void
    {
        $admin = User::factory()->admin()->create();
        $sender = $this->instructor();
        $inactive = $this->driver();
        $inactive->is_active = false;
        $inactive->save();
        $deputy = $this->driver();
        $room = $this->columnRoom($admin, '15');
        $this->member($room, $sender, ChatMemberRole::MODERATOR);
        $this->member($room, $inactive);
        ChatMember::create([
            'room_id' => $room->id,
            'user_id' => $deputy->id,
            'role' => ChatMemberRole::MODERATOR,
            'source' => ChatMemberSource::DEPUTY,
            'window_starts_on' => '2026-01-01',
            'window_ends_on' => '2026-01-31',
            'added_by' => $admin->id,
        ]);

        app(ChatService::class)->sendText($sender, $room, (string) Str::uuid(), 'Привет');
        $this->assertDatabaseCount('user_notifications', 0);
    }

    public function test_marking_chat_read_clears_chat_notification(): void
    {
        $admin = User::factory()->admin()->create();
        $sender = $this->instructor();
        $peer = $this->driver();
        $room = $this->columnRoom($admin, '15');
        $this->member($room, $sender, ChatMemberRole::MODERATOR);
        $this->member($room, $peer);
        $sent = $this->api($sender, 'post', '/chats/'.$room->id.'/messages', [
            'client_id' => (string) Str::uuid(),
            'type' => 'text',
            'body' => 'Читать',
        ])->assertCreated();

        $this->api($peer, 'post', '/chats/'.$room->id.'/read', [
            'last_read_message_id' => $sent->json('id'),
        ])->assertOk();

        $this->assertNotNull(UserNotification::query()->where('user_id', $peer->id)->firstOrFail()->read_at);
        $this->api($peer, 'get', '/notifications')->assertOk()->assertJsonPath('unread', 0);
    }

    public function test_formular_submit_notifies_column_instructor_and_review_notifies_driver(): void
    {
        $driver = $this->driver();
        $instructor = $this->instructor();
        $other = $this->instructor('16');
        $task = FormularTask::create(['title' => 'Проверка', 'is_active' => true, 'category' => 'formular', 'sort_order' => 1]);
        $service = app(FormularService::class);
        $submitted = $service->mutate($driver, 'submit', $task->id, [
            'operation_id' => (string) Str::uuid(),
            'version' => 0,
            'entry_text' => 'Текст записи формуляра не должен попасть в оповещение',
        ]);

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $instructor->id,
            'type' => UserNotificationType::FormularEntry->value,
            'title' => 'Новая запись в формуляр',
        ]);
        $this->assertDatabaseMissing('user_notifications', ['user_id' => $other->id]);
        $this->assertDatabaseMissing('user_notifications', ['user_id' => $driver->id]);
        $this->assertStringNotContainsString('Текст записи', json_encode(UserNotification::query()->pluck('title')));

        $logId = (int) $submitted['log']['id'];
        $service->mutate($instructor, 'returned', $logId, [
            'operation_id' => (string) Str::uuid(),
            'version' => 1,
            'review_comment' => 'Замечание тоже не в пуш',
        ]);

        $review = UserNotification::query()
            ->where('user_id', $driver->id)
            ->where('type', UserNotificationType::FormularReview)
            ->firstOrFail();
        $this->assertSame('Запись в формуляр возвращена', $review->title);
        $this->assertStringNotContainsString('Замечание', $review->title);
    }

    public function test_discipline_assigned_creates_instructor_notice(): void
    {
        $manager = User::factory()->create(['role' => 'tormoznik']);
        $instructor = $this->instructor();
        $assignment = app(DisciplineService::class)->create($manager, [
            'operation_id' => (string) Str::uuid(),
            'title' => 'Карточка',
            'instructions' => 'Сделать',
            'due_date' => today()->addDays(3)->toDateString(),
            'all_instructors' => false,
            'assignee_ids' => [$instructor->id],
            'require_response' => true,
            'require_report_file' => false,
            'require_explanation_file' => false,
        ]);
        $this->assertNotNull(DisciplineAssignment::query()->where('card_id', $assignment['card_id'])->first());
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $instructor->id,
            'type' => UserNotificationType::InstructorNotice->value,
            'title' => 'Новое поручение',
        ]);
    }

    public function test_web_inbox_mark_read_and_push_subscription(): void
    {
        $user = $this->instructor();
        UserNotification::query()->create([
            'user_id' => $user->id,
            'type' => UserNotificationType::InstructorNotice,
            'resource_type' => 'discipline_assignment',
            'resource_id' => 1,
            'title' => 'Новое поручение',
            'count' => 1,
        ]);

        $this->actingAs($user)->get('/notifications')->assertOk()->assertSee('Новое поручение');
        $inbox = $this->actingAs($user)->getJson('/notifications/inbox.json')->assertOk();
        $id = $inbox->json('items.0.id');
        $this->actingAs($user)->postJson('/notifications/'.$id.'/read')->assertOk()->assertJsonPath('ok', true);
        $this->assertNotNull(UserNotification::query()->find($id)?->read_at);

        $this->actingAs($user)->postJson('/notifications/push-subscription', [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/test-endpoint',
            'keys' => ['p256dh' => 'abc', 'auth' => 'def'],
        ])->assertCreated();
        $this->assertDatabaseHas('push_subscriptions', [
            'user_id' => $user->id,
            'channel' => 'web',
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/test-endpoint',
        ]);
        $this->actingAs($user)->deleteJson('/notifications/push-subscription', [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/test-endpoint',
        ])->assertOk();
        $this->assertDatabaseCount('push_subscriptions', 0);
    }

    public function test_http_push_endpoint_is_rejected(): void
    {
        $user = $this->instructor();
        $this->actingAs($user)->postJson('/notifications/push-subscription', [
            'endpoint' => 'http://example.test/push',
        ])->assertStatus(422);
        $this->assertDatabaseCount('push_subscriptions', 0);
    }

    public function test_web_push_sends_empty_payload_and_drops_gone_subscription(): void
    {
        $key = openssl_pkey_new([
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);
        $details = openssl_pkey_get_details($key);
        $pem = '';
        openssl_pkey_export($key, $pem);
        config([
            'notifications.vapid.public_key' => rtrim(strtr(base64_encode("\x04".$details['ec']['x'].$details['ec']['y']), '+/', '-_'), '='),
            'notifications.vapid.private_key' => $pem,
            'notifications.vapid.subject' => 'mailto:noreply@localhost',
        ]);
        Http::fake([
            'https://push.example.test/gone' => Http::response('', 410),
            'https://push.example.test/ok' => Http::response('', 201),
        ]);
        $user = $this->instructor();
        PushSubscription::query()->create([
            'user_id' => $user->id,
            'channel' => 'web',
            'endpoint' => 'https://push.example.test/gone',
        ]);
        PushSubscription::query()->create([
            'user_id' => $user->id,
            'channel' => 'web',
            'endpoint' => 'https://push.example.test/ok',
        ]);

        app(WebPushPing::class)->pingUsers([$user->id]);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://push.example.test/ok'
                && $request->method() === 'POST'
                && $request->body() === ''
                && str_starts_with((string) $request->header('Authorization')[0], 'vapid t=');
        });
        $this->assertDatabaseMissing('push_subscriptions', ['endpoint' => 'https://push.example.test/gone']);
        $this->assertDatabaseHas('push_subscriptions', ['endpoint' => 'https://push.example.test/ok']);
    }

    public function test_disabled_inbox_is_hidden(): void
    {
        config(['notifications.enabled' => false]);
        $user = $this->instructor();
        $this->actingAs($user)->get('/notifications')->assertNotFound();
        $this->api($user, 'get', '/notifications')->assertNotFound();
        $this->api($user, 'get', '/me')->assertOk()->assertJsonPath('user.features.notifications', false);
    }

    public function test_available_attachment_notifies_once(): void
    {
        $admin = User::factory()->admin()->create();
        $sender = $this->instructor();
        $peer = $this->driver();
        $room = $this->columnRoom($admin, '15');
        $this->member($room, $sender, ChatMemberRole::MODERATOR);
        $this->member($room, $peer);
        $message = ChatMessage::create([
            'room_id' => $room->id,
            'sender_id' => $sender->id,
            'client_id' => (string) Str::uuid(),
            'payload_fingerprint' => hash('sha256', "image\n\n"),
            'type' => ChatMessageType::IMAGE,
            'status' => ChatMessageStatus::AVAILABLE,
        ]);
        app(UserNotifications::class)->chatMessage($message);
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $peer->id,
            'type' => UserNotificationType::ChatMessage->value,
        ]);
        $this->assertDatabaseMissing('user_notifications', ['user_id' => $sender->id]);
    }

    private function instructor(string $column = '15'): User
    {
        $user = User::factory()->instructor()->create();
        UserProfile::create(['user_id' => $user->id, 'column' => $column]);

        return $user->fresh();
    }

    private function driver(string $column = '15'): User
    {
        $user = User::factory()->driver()->create();
        UserProfile::create(['user_id' => $user->id, 'column' => $column]);

        return $user->fresh();
    }

    private function columnRoom(User $creator, string $column, ?string $title = null): ChatRoom
    {
        return ChatRoom::create([
            'type' => ChatType::COLUMN,
            'title' => $title ?? 'Колонна '.$column,
            'column' => $column,
            'created_by' => $creator->id,
        ]);
    }

    private function member(
        ChatRoom $room,
        User $user,
        ChatMemberRole $role = ChatMemberRole::MEMBER,
        ChatMemberSource $source = ChatMemberSource::REGULAR,
    ): ChatMember {
        return ChatMember::create([
            'room_id' => $room->id,
            'user_id' => $user->id,
            'role' => $role,
            'source' => $source,
            'added_by' => $user->id,
        ]);
    }

    private function api(User $user, string $method, string $path, array $data = [])
    {
        $this->app['auth']->forgetGuards();
        $token = $user->createToken('mobile')->plainTextToken;

        return $this->withToken($token)->json($method, '/api/mobile'.$path, $data);
    }
}
