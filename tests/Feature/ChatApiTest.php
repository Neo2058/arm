<?php

namespace Tests\Feature;

use App\Enums\ChatMemberRole;
use App\Enums\ChatMemberSource;
use App\Enums\ChatMessageStatus;
use App\Enums\ChatMessageType;
use App\Enums\ChatType;
use App\Models\ChatMember;
use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\Chat\ChatCrypto;
use App\Services\Chat\ChatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class ChatApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'chat.enabled' => true,
            'chat.pilot_column' => null,
            'chat.timezone' => 'Europe/Moscow',
        ]);
        Carbon::setTestNow(Carbon::parse('2026-10-20 12:00:00', 'Europe/Moscow'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_membership_in_an_old_room_is_visible_in_the_next_sync(): void
    {
        $admin = User::factory()->admin()->create();
        $user = $this->instructor();
        $old = $this->columnRoom($admin, '15', 'Старая');
        $this->member($old, $admin);
        $this->text($old, $admin, 'старое');

        $fresh = $this->columnRoom($admin, '15', 'Новая');
        $this->member($fresh, $user, ChatMemberRole::MODERATOR);
        $this->text($fresh, $user, 'новое');

        $sync = $this->api($user, 'get', '/chats/sync')->assertOk();
        $ids = collect($sync->json('rooms'))->pluck('id')->all();
        $this->assertContains($fresh->id, $ids);
        $this->assertNotContains($old->id, $ids);
        $this->assertGreaterThan(
            (int) ChatMessage::query()->where('room_id', $old->id)->max('id'),
            (int) ChatMessage::query()->where('room_id', $fresh->id)->max('id'),
        );

        $this->member($old, $user, ChatMemberRole::MODERATOR);
        $again = $this->api($user, 'get', '/chats/sync')->assertOk();
        $ids = collect($again->json('rooms'))->pluck('id')->all();
        $this->assertContains($old->id, $ids);
        $this->assertContains($fresh->id, $ids);
    }

    public function test_empty_room_appears_in_sync_with_last_message_null(): void
    {
        $user = $this->instructor();
        $room = $this->columnRoom(User::factory()->admin()->create(), '15', 'Пустая');
        $this->member($room, $user, ChatMemberRole::MODERATOR);

        $this->api($user, 'get', '/chats/sync')
            ->assertOk()
            ->assertJsonPath('rooms.0.id', $room->id)
            ->assertJsonPath('rooms.0.last_message', null)
            ->assertJsonPath('rooms.0.unread', 0);
    }

    public function test_me_sets_enrollment_once_without_a_new_login(): void
    {
        $user = $this->instructor();
        $this->assertNull($user->mobile_enrolled_at);
        $token = $user->createToken('mobile')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/mobile/me')
            ->assertOk()
            ->assertJsonPath('user.features.chat', true)
            ->assertJsonPath('user.column', '15')
            ->assertJsonPath('user.can_manage_column_chats', true);

        $first = $user->fresh()->mobile_enrolled_at;
        $this->assertNotNull($first);

        Carbon::setTestNow($first->copy()->addHour());
        $this->withToken($token)->getJson('/api/mobile/me')->assertOk();
        $this->assertTrue($first->eq($user->fresh()->mobile_enrolled_at));
    }

    public function test_login_sets_enrollment_and_features_chat(): void
    {
        $user = $this->instructor();
        $this->assertNull($user->mobile_enrolled_at);

        $this->postJson('/api/mobile/login', [
            'email' => $user->email,
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonPath('user.features.chat', true)
            ->assertJsonPath('user.column', '15')
            ->assertJsonPath('user.can_manage_column_chats', true);

        $this->assertNotNull($user->fresh()->mobile_enrolled_at);
    }

    public function test_fingerprint_mismatch_is_409_and_same_fingerprint_is_200(): void
    {
        $user = $this->instructor();
        $room = $this->columnRoom(User::factory()->admin()->create(), '15');
        $this->member($room, $user, ChatMemberRole::MODERATOR);
        $clientId = (string) Str::uuid();

        $first = $this->api($user, 'post', '/chats/'.$room->id.'/messages', [
            'client_id' => $clientId,
            'type' => 'text',
            'body' => 'На смену',
        ])->assertCreated()->assertJsonPath('status', 'available')->assertJsonPath('client_id', $clientId);

        $this->assertDatabaseMissing('chat_messages', ['body_ciphertext' => 'На смену']);
        $row = ChatMessage::query()->findOrFail($first->json('id'));
        $this->assertNotSame('На смену', $row->body_ciphertext);
        $this->assertSame('На смену', app(ChatCrypto::class)->decrypt($row->body_ciphertext));

        $this->api($user, 'post', '/chats/'.$room->id.'/messages', [
            'client_id' => $clientId,
            'type' => 'text',
            'body' => 'На смену',
        ])->assertOk()->assertJsonPath('id', $first->json('id'))->assertJsonPath('status', 'available');

        $this->api($user, 'post', '/chats/'.$room->id.'/messages', [
            'client_id' => $clientId,
            'type' => 'text',
            'body' => 'Другое',
        ])->assertStatus(409)->assertJsonPath('message', 'Этот client_id уже использован с другим содержимым.');

        $this->assertSame(1, ChatMessage::query()->where('room_id', $room->id)->count());

        $this->api($user, 'post', '/chats/'.$room->id.'/messages', [
            'client_id' => (string) Str::uuid(),
            'type' => 'image',
            'body' => 'фото',
        ])->assertUnprocessable();
    }

    public function test_deputy_moscow_window_update_and_revoke_via_http(): void
    {
        $admin = User::factory()->admin()->create();
        $deputy = User::factory()->driver()->create(['name' => 'Заместитель']);
        UserProfile::create(['user_id' => $deputy->id, 'column' => '15']);
        $room = $this->columnRoom($admin, '15');
        $period = app(ChatService::class)->grant($admin, $deputy, '15', '2026-10-01', '2026-10-31', 'отпуск');

        $this->api($deputy, 'get', '/chats/sync')
            ->assertOk()
            ->assertJsonPath('rooms.0.id', $room->id)
            ->assertJsonPath('rooms.0.can_manage_members', true);

        $this->api($deputy, 'post', '/chats/'.$room->id.'/messages', [
            'client_id' => (string) Str::uuid(),
            'type' => 'text',
            'body' => 'внутри окна',
        ])->assertCreated();

        app(ChatService::class)->updateWindow($period, '2026-10-01', '2026-10-19');
        $this->api($deputy, 'get', '/chats/sync')->assertOk()->assertJsonPath('rooms', []);
        $this->api($deputy, 'post', '/chats/'.$room->id.'/messages', [
            'client_id' => (string) Str::uuid(),
            'type' => 'text',
            'body' => 'после окна',
        ])->assertForbidden();

        $period = app(ChatService::class)->grant($admin, $deputy, '15', '2026-10-01', '2026-10-31', 'снова');
        $this->api($deputy, 'post', '/chats/'.$room->id.'/messages', [
            'client_id' => (string) Str::uuid(),
            'type' => 'text',
            'body' => 'снова внутри',
        ])->assertCreated();
        app(ChatService::class)->revoke($period);
        $this->api($deputy, 'get', '/chats/sync')->assertOk()->assertJsonPath('rooms', []);
        $this->api($deputy, 'post', '/chats/'.$room->id.'/messages', [
            'client_id' => (string) Str::uuid(),
            'type' => 'text',
            'body' => 'после revoke',
        ])->assertForbidden();

        $regular = User::factory()->driver()->create();
        UserProfile::create(['user_id' => $regular->id, 'column' => '15']);
        $this->member($room, $regular);
        $overlay = app(ChatService::class)->grant($admin, $regular, '15', '2026-10-01', '2026-10-31', 'overlay');
        $this->api($regular, 'get', '/chats/sync')
            ->assertOk()
            ->assertJsonPath('rooms.0.id', $room->id)
            ->assertJsonPath('rooms.0.can_manage_members', true);
        app(ChatService::class)->revoke($overlay);
        $this->assertDatabaseHas('chat_members', [
            'room_id' => $room->id,
            'user_id' => $regular->id,
            'source' => ChatMemberSource::REGULAR->value,
        ]);
        $this->api($regular, 'get', '/chats/sync')
            ->assertOk()
            ->assertJsonPath('rooms.0.id', $room->id)
            ->assertJsonPath('rooms.0.can_manage_members', false);
        $this->api($regular, 'post', '/chats/'.$room->id.'/messages', [
            'client_id' => (string) Str::uuid(),
            'type' => 'text',
            'body' => 'regular после revoke',
        ])->assertCreated();
    }

    public function test_archived_room_is_410_and_omitted_from_sync(): void
    {
        $user = $this->instructor();
        $room = $this->columnRoom(User::factory()->admin()->create(), '15');
        $this->member($room, $user, ChatMemberRole::MODERATOR);
        $room->archived_at = now();
        $room->save();

        $this->api($user, 'get', '/chats/sync')->assertOk()->assertJsonPath('rooms', []);
        $this->api($user, 'get', '/chats/'.$room->id)
            ->assertStatus(410)
            ->assertJsonPath('message', 'Чат архивирован.');
        $this->api($user, 'post', '/chats/'.$room->id.'/messages', [
            'client_id' => (string) Str::uuid(),
            'type' => 'text',
            'body' => 'архив',
        ])->assertStatus(410);
        $this->api($user, 'get', '/chats/'.$room->id.'/messages')->assertStatus(410);
        $this->api($user, 'post', '/chats/'.$room->id.'/read', ['last_read_message_id' => 1])->assertStatus(410);
        $this->api($user, 'get', '/chats/'.$room->id.'/members')->assertStatus(410);
    }

    public function test_non_member_of_visible_room_is_403(): void
    {
        $admin = User::factory()->admin()->create();
        $member = $this->instructor();
        $stranger = $this->instructor();
        $room = $this->columnRoom($admin, '15');
        $this->member($room, $member, ChatMemberRole::MODERATOR);

        $this->api($stranger, 'get', '/chats/'.$room->id)->assertForbidden();
        $this->api($stranger, 'get', '/chats/'.$room->id.'/messages')->assertForbidden();
        $this->api($stranger, 'post', '/chats/'.$room->id.'/messages', [
            'client_id' => (string) Str::uuid(),
            'type' => 'text',
            'body' => 'чужой',
        ])->assertForbidden();
    }

    public function test_last_moderator_and_self_remove_are_422(): void
    {
        $admin = User::factory()->admin()->create();
        $first = $this->instructor('15', 'Иванов');
        $second = $this->instructor('15', 'Петров');
        $driver = User::factory()->driver()->create(['name' => 'Сидоров']);
        $driver->mobile_enrolled_at = now();
        $driver->save();
        UserProfile::create(['user_id' => $driver->id, 'column' => '15']);
        $room = $this->columnRoom($admin, '15');
        $this->member($room, $first, ChatMemberRole::MODERATOR);
        $this->member($room, $second);
        $this->member($room, $driver);

        $this->api($first, 'delete', '/chats/'.$room->id.'/members/'.$first->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['user']);
        $this->api($second, 'delete', '/chats/'.$room->id.'/members/'.$first->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['user']);
        $this->api($first, 'delete', '/chats/'.$room->id.'/members/'.$second->id)->assertOk();
        $this->api($first, 'delete', '/chats/'.$room->id.'/members/'.$driver->id)->assertOk();
        $this->assertDatabaseMissing('chat_members', [
            'room_id' => $room->id,
            'user_id' => $driver->id,
        ]);

        $deputy = User::factory()->driver()->create();
        UserProfile::create(['user_id' => $deputy->id, 'column' => '15']);
        app(ChatService::class)->grant($admin, $deputy, '15', '2026-10-01', '2026-10-31', 'отпуск');
        $this->api($first, 'delete', '/chats/'.$room->id.'/members/'.$deputy->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['user']);
    }

    public function test_pilot_column_hides_other_column_rooms_with_404(): void
    {
        config(['chat.pilot_column' => '15']);
        $admin = User::factory()->admin()->create();
        $user = $this->instructor('15');
        $otherUser = $this->instructor('99');
        $pilot = $this->columnRoom($admin, '15');
        $hidden = $this->columnRoom($admin, '99');
        $this->member($pilot, $user, ChatMemberRole::MODERATOR);
        $this->member($hidden, $user, ChatMemberRole::MODERATOR);
        $this->member($hidden, $otherUser, ChatMemberRole::MODERATOR);
        $stranger = User::factory()->driver()->create();

        $this->api($user, 'get', '/chats/sync')
            ->assertOk()
            ->assertJsonPath('rooms.0.id', $pilot->id)
            ->assertJsonCount(1, 'rooms');
        $this->api($user, 'get', '/chats/'.$hidden->id)->assertNotFound();
        $this->api($user, 'post', '/chats/'.$hidden->id.'/messages', [
            'client_id' => (string) Str::uuid(),
            'type' => 'text',
            'body' => 'пилот',
        ])->assertNotFound();
        $this->api($otherUser, 'get', '/chats/'.$hidden->id)->assertNotFound();
        $this->api($stranger, 'get', '/chats/'.$hidden->id)->assertNotFound();
        $this->api($otherUser, 'get', '/chats/sync')->assertOk()->assertJsonPath('rooms', []);
    }

    public function test_chat_disabled_returns_404_on_mobile_chat_paths(): void
    {
        config(['chat.enabled' => false]);
        $user = $this->instructor();
        $room = $this->columnRoom(User::factory()->admin()->create(), '15');
        $this->member($room, $user, ChatMemberRole::MODERATOR);
        $other = User::factory()->driver()->create();

        $this->withToken($user->createToken('mobile')->plainTextToken)
            ->getJson('/api/mobile/me')
            ->assertOk()
            ->assertJsonPath('user.features.chat', false);

        $this->api($user, 'get', '/chats/sync')->assertNotFound();
        $this->api($user, 'get', '/chats/'.$room->id)->assertNotFound();
        $this->api($user, 'get', '/chats/'.$room->id.'/messages')->assertNotFound();
        $this->api($user, 'post', '/chats/'.$room->id.'/messages', [
            'client_id' => (string) Str::uuid(),
            'type' => 'text',
            'body' => 'выкл',
        ])->assertNotFound();
        $this->api($user, 'post', '/chats/'.$room->id.'/read', ['last_read_message_id' => 1])->assertNotFound();
        $this->api($user, 'get', '/chats/'.$room->id.'/members')->assertNotFound();
        $this->api($user, 'get', '/chats/'.$room->id.'/eligible-members')->assertNotFound();
        $this->api($user, 'post', '/chats/'.$room->id.'/members', ['user_id' => $other->id])->assertNotFound();
        $this->api($user, 'delete', '/chats/'.$room->id.'/members/'.$other->id)->assertNotFound();
    }

    public function test_queued_messages_are_hidden_from_last_message_and_unread(): void
    {
        $user = $this->instructor();
        $room = $this->columnRoom(User::factory()->admin()->create(), '15');
        $this->member($room, $user, ChatMemberRole::MODERATOR);
        $available = $this->text($room, $user, 'видимое');
        ChatMessage::create([
            'room_id' => $room->id,
            'sender_id' => $user->id,
            'client_id' => (string) Str::uuid(),
            'payload_fingerprint' => hash('sha256', "image\n\n"),
            'type' => ChatMessageType::IMAGE,
            'status' => ChatMessageStatus::QUEUED,
        ]);

        $this->api($user, 'get', '/chats/sync')
            ->assertOk()
            ->assertJsonPath('rooms.0.unread', 1)
            ->assertJsonPath('rooms.0.last_message.id', $available->id)
            ->assertJsonPath('rooms.0.last_message.type', 'text')
            ->assertJsonPath('rooms.0.last_message.preview', 'видимое');

        $feed = $this->api($user, 'get', '/chats/'.$room->id.'/messages')->assertOk();
        $this->assertSame([$available->id], collect($feed->json('messages'))->pluck('id')->all());
        $this->assertSame('видимое', $feed->json('messages.0.body'));
        $this->assertArrayNotHasKey('body_ciphertext', $feed->json('messages.0'));
    }

    public function test_read_cursor_is_monotonic_and_must_belong_to_room(): void
    {
        $user = $this->instructor();
        $admin = User::factory()->admin()->create();
        $room = $this->columnRoom($admin, '15');
        $other = $this->columnRoom($admin, '15', 'Другая');
        $this->member($room, $user, ChatMemberRole::MODERATOR);
        $first = $this->text($room, $user, 'одно');
        $second = $this->text($room, $user, 'два');
        $foreign = $this->text($other, $admin, 'чужое');

        $this->api($user, 'post', '/chats/'.$room->id.'/read', [
            'last_read_message_id' => $second->id,
        ])->assertOk();
        $this->api($user, 'get', '/chats/sync')->assertJsonPath('rooms.0.unread', 0);

        $this->api($user, 'post', '/chats/'.$room->id.'/read', [
            'last_read_message_id' => $first->id,
        ])->assertOk();
        $this->assertSame($second->id, (int) ChatMember::query()
            ->where('room_id', $room->id)
            ->where('user_id', $user->id)
            ->value('last_read_message_id'));

        $this->api($user, 'post', '/chats/'.$room->id.'/read', [
            'last_read_message_id' => $foreign->id,
        ])->assertUnprocessable()->assertJsonValidationErrors(['last_read_message_id']);
    }

    public function test_add_member_writes_system_message_and_rejects_ineligible(): void
    {
        $admin = User::factory()->admin()->create();
        $instructor = $this->instructor();
        $room = $this->columnRoom($admin, '15');
        $this->member($room, $instructor, ChatMemberRole::MODERATOR);

        $enrolled = User::factory()->driver()->create(['name' => 'Сидоров С.С.']);
        $enrolled->mobile_enrolled_at = now();
        $enrolled->save();
        UserProfile::create(['user_id' => $enrolled->id, 'column' => '15']);

        $otherColumn = User::factory()->driver()->create();
        $otherColumn->mobile_enrolled_at = now();
        $otherColumn->save();
        UserProfile::create(['user_id' => $otherColumn->id, 'column' => '99']);

        $notEnrolled = User::factory()->driver()->create();
        UserProfile::create(['user_id' => $notEnrolled->id, 'column' => '15']);

        $this->api($instructor, 'post', '/chats/'.$room->id.'/members', ['user_id' => $otherColumn->id])
            ->assertUnprocessable();
        $this->api($instructor, 'post', '/chats/'.$room->id.'/members', ['user_id' => $notEnrolled->id])
            ->assertUnprocessable();
        $this->api($instructor, 'post', '/chats/'.$room->id.'/members', ['user_id' => $enrolled->id])
            ->assertCreated();
        $this->api($instructor, 'post', '/chats/'.$room->id.'/members', ['user_id' => $enrolled->id])
            ->assertUnprocessable();

        $this->api($enrolled, 'get', '/chats/sync')->assertJsonPath('rooms.0.id', $room->id);
        $messages = $this->api($instructor, 'get', '/chats/'.$room->id.'/messages')->assertOk()->json('messages');
        $system = collect($messages)->firstWhere('type', 'system');
        $this->assertNotNull($system);
        $this->assertNull($system['sender_id']);
        $this->assertSame('Добавлен Сидоров С.С.', $system['body']);

        $searchable = User::factory()->driver()->create(['name' => 'Karen Test']);
        $searchable->mobile_enrolled_at = now();
        $searchable->save();
        UserProfile::create(['user_id' => $searchable->id, 'column' => '15']);

        $eligible = $this->api($instructor, 'get', '/chats/'.$room->id.'/eligible-members')->assertOk();
        $ids = collect($eligible->json('users'))->pluck('id')->all();
        $this->assertNotContains($enrolled->id, $ids);
        $this->assertNotContains($instructor->id, $ids);
        $this->assertNotContains($otherColumn->id, $ids);
        $this->assertNotContains($notEnrolled->id, $ids);
        $this->assertContains($searchable->id, $ids);
        $this->app['auth']->forgetGuards();
        $byName = $this->withToken($instructor->createToken('mobile')->plainTextToken)
            ->getJson('/api/mobile/chats/'.$room->id.'/eligible-members?'.http_build_query(['q' => 'Karen']))
            ->assertOk();
        $this->assertSame([$searchable->id], collect($byName->json('users'))->pluck('id')->all());

        $this->api($instructor, 'get', '/chats/'.$room->id)
            ->assertOk()
            ->assertJsonPath('id', $room->id)
            ->assertJsonPath('type', 'column')
            ->assertJsonPath('can_manage_members', true)
            ->assertJsonPath('members_count', 2);
    }

    public function test_deleted_and_rejected_rows_use_stub_payloads(): void
    {
        $user = $this->instructor();
        $other = User::factory()->driver()->create();
        $room = $this->columnRoom(User::factory()->admin()->create(), '15');
        $this->member($room, $user, ChatMemberRole::MODERATOR);
        $this->member($room, $other);
        $deleted = $this->text($room, $user, 'тайна');
        $deleted->deleted_at = now();
        $deleted->deleted_by = $user->id;
        $deleted->save();
        ChatMessage::create([
            'room_id' => $room->id,
            'sender_id' => $user->id,
            'client_id' => (string) Str::uuid(),
            'payload_fingerprint' => hash('sha256', "pdf\n\nabc"),
            'type' => ChatMessageType::PDF,
            'status' => ChatMessageStatus::REJECTED,
        ]);

        $this->api($user, 'get', '/chats/sync')
            ->assertOk()
            ->assertJsonPath('rooms.0.unread', 0)
            ->assertJsonPath('rooms.0.last_message.type', 'deleted')
            ->assertJsonPath('rooms.0.last_message.preview', 'Сообщение удалено');

        $own = $this->api($user, 'get', '/chats/'.$room->id.'/messages')->assertOk()->json('messages');
        $this->assertSame('deleted', $own[0]['type']);
        $this->assertArrayNotHasKey('body', $own[0]);
        $this->assertSame('rejected', $own[1]['status']);
        $this->assertArrayNotHasKey('body', $own[1]);

        $theirs = $this->api($other, 'get', '/chats/'.$room->id.'/messages')->assertOk()->json('messages');
        $this->assertCount(1, $theirs);
        $this->assertSame('deleted', $theirs[0]['type']);
    }

    private function instructor(string $column = '15', ?string $name = null): User
    {
        $user = User::factory()->instructor()->create($name ? ['name' => $name] : []);
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

    private function text(ChatRoom $room, User $user, string $body): ChatMessage
    {
        return ChatMessage::create([
            'room_id' => $room->id,
            'sender_id' => $user->id,
            'client_id' => (string) Str::uuid(),
            'payload_fingerprint' => hash('sha256', "text\n".$body."\n"),
            'type' => ChatMessageType::TEXT,
            'status' => ChatMessageStatus::AVAILABLE,
            'body_ciphertext' => app(ChatCrypto::class)->encrypt($body),
        ]);
    }

    private function api(User $user, string $method, string $path, array $data = [])
    {
        $this->app['auth']->forgetGuards();
        $token = $user->createToken('mobile')->plainTextToken;

        return $this->withToken($token)->json($method, '/api/mobile'.$path, $data);
    }
}
