<?php

namespace Tests\Feature;

use App\Enums\ChatMemberRole;
use App\Enums\ChatMemberSource;
use App\Enums\ChatMessageStatus;
use App\Enums\ChatMessageType;
use App\Enums\ChatType;
use App\Enums\UserRole;
use App\Filament\Resources\ChatRoomResource;
use App\Filament\Resources\ChatRoomResource\Pages\CreateChatRoom;
use App\Filament\Resources\ChatRoomResource\Pages\EditChatRoom;
use App\Filament\Resources\ChatRoomResource\Pages\ListChatRooms;
use App\Filament\Resources\ChatRoomResource\Pages\ViewChatRoom;
use App\Filament\Resources\ColumnDeputyResource;
use App\Models\ChatMember;
use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\Chat\ChatCrypto;
use App\Services\Chat\ChatService;
use App\Services\ClickHouseService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class ChatRoomResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        ClickHouseService::fake();
        config([
            'chat.enabled' => true,
            'chat.pilot_column' => null,
            'chat.timezone' => 'Europe/Moscow',
        ]);
        Carbon::setTestNow(Carbon::parse('2026-10-20 12:00:00', 'Europe/Moscow'));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_admin_can_access_chat_room_pages(): void
    {
        $admin = User::factory()->admin()->create();
        $room = $this->columnRoom($admin, '15');
        $this->actingAs($admin);

        $this->assertTrue(ChatRoomResource::canAccess());
        $this->assertTrue(ChatRoomResource::shouldRegisterNavigation());

        $this->get(ChatRoomResource::getUrl('index'))->assertOk();
        $this->get(ChatRoomResource::getUrl('create'))->assertOk();
        $this->get(ChatRoomResource::getUrl('view', ['record' => $room]))->assertOk();
        $this->get(ChatRoomResource::getUrl('edit', ['record' => $room]))->assertOk();

        Livewire::test(ListChatRooms::class)->assertSuccessful();
        Livewire::test(CreateChatRoom::class)->assertSuccessful();
        Livewire::test(ViewChatRoom::class, ['record' => $room->id])->assertSuccessful();
        Livewire::test(EditChatRoom::class, ['record' => $room->id])->assertSuccessful();
    }

    public function test_non_admin_cannot_access_chat_room_pages(): void
    {
        $instructor = User::factory()->instructor()->create();
        $this->actingAs($instructor);

        $this->assertFalse(ChatRoomResource::canAccess());
        $this->assertFalse(ChatRoomResource::shouldRegisterNavigation());
        $this->assertFalse(ColumnDeputyResource::canAccess());

        $this->get('/admin/chat-rooms')->assertForbidden();
        $this->get('/admin/column-deputies')->assertForbidden();

        Livewire::test(ListChatRooms::class)->assertForbidden();
        Livewire::test(CreateChatRoom::class)->assertForbidden();
    }

    public function test_navigation_does_not_depend_on_chat_enabled(): void
    {
        config(['chat.enabled' => false]);
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $this->assertTrue(ChatRoomResource::canAccess());
        $this->assertTrue(ChatRoomResource::shouldRegisterNavigation());
        $this->assertTrue(ColumnDeputyResource::canAccess());
        $this->assertTrue(ColumnDeputyResource::shouldRegisterNavigation());
        $this->get(ChatRoomResource::getUrl('index'))->assertOk();
        $this->get(ColumnDeputyResource::getUrl('index'))->assertOk();
        $this->get(ChatRoomResource::getUrl('create'))->assertOk();
    }

    public function test_column_create_seeds_instructors_and_current_deputies(): void
    {
        $admin = User::factory()->admin()->create();
        $instructor = $this->instructor('15', 'Иванов');
        $otherColumn = $this->instructor('99', 'Петров');
        $inactive = $this->instructor('15', 'Сидоров', false);
        $deputy = User::factory()->driver()->create(['name' => 'Заместитель']);
        UserProfile::create(['user_id' => $deputy->id, 'column' => '15']);
        app(ChatService::class)->grant($admin, $deputy, '15', '2026-10-01', '2026-10-31', 'отпуск');

        $this->actingAs($admin);
        Livewire::test(CreateChatRoom::class)
            ->fillForm([
                'title' => 'Колонна 15',
                'type' => ChatType::COLUMN->value,
                'column' => ' 15 ',
                'archived' => false,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $room = ChatRoom::query()->firstOrFail();
        $this->assertSame('15', $room->column);
        $this->assertSame(ChatType::COLUMN, $room->type);
        $this->assertNull($room->archived_at);
        $this->assertFalse(ChatRoomResource::canDelete($room));

        $this->assertDatabaseHas('chat_members', [
            'room_id' => $room->id,
            'user_id' => $instructor->id,
            'role' => ChatMemberRole::MODERATOR->value,
            'source' => ChatMemberSource::REGULAR->value,
        ]);
        $this->assertDatabaseHas('chat_members', [
            'room_id' => $room->id,
            'user_id' => $deputy->id,
            'role' => ChatMemberRole::MODERATOR->value,
            'source' => ChatMemberSource::DEPUTY->value,
        ]);
        $this->assertDatabaseMissing('chat_members', [
            'room_id' => $room->id,
            'user_id' => $otherColumn->id,
        ]);
        $this->assertDatabaseMissing('chat_members', [
            'room_id' => $room->id,
            'user_id' => $inactive->id,
        ]);
    }

    public function test_leadership_create_does_not_role_seed(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->create(['role' => UserRole::NACHALNIK, 'name' => 'Начальник']);
        $this->actingAs($admin);

        Livewire::test(CreateChatRoom::class)
            ->fillForm([
                'title' => 'Руководители',
                'type' => ChatType::LEADERSHIP->value,
                'archived' => false,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $room = ChatRoom::query()->firstOrFail();
        $this->assertSame(ChatType::LEADERSHIP, $room->type);
        $this->assertNull($room->column);
        $this->assertSame(0, ChatMember::query()->where('room_id', $room->id)->count());
    }

    public function test_unknown_column_is_a_warning_not_a_block(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Livewire::test(CreateChatRoom::class)
            ->fillForm([
                'title' => 'Пилот',
                'type' => ChatType::COLUMN->value,
                'column' => 'нет-такой',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('chat_rooms', [
            'title' => 'Пилот',
            'column' => 'нет-такой',
        ]);
    }

    public function test_view_feed_decrypts_only_the_current_page_and_hides_queued(): void
    {
        $admin = User::factory()->admin()->create();
        $author = User::factory()->driver()->create(['name' => 'Автор']);
        $queuedAuthor = User::factory()->driver()->create(['name' => 'Queued Sender Unique']);
        $room = $this->columnRoom($admin, '15');

        for ($i = 1; $i <= 110; $i++) {
            ChatMessage::create([
                'room_id' => $room->id,
                'sender_id' => $author->id,
                'client_id' => (string) Str::uuid(),
                'type' => ChatMessageType::TEXT,
                'status' => ChatMessageStatus::AVAILABLE,
                'body_ciphertext' => 'cipher-'.$i,
            ]);
        }
        ChatMessage::create([
            'room_id' => $room->id,
            'sender_id' => $queuedAuthor->id,
            'client_id' => (string) Str::uuid(),
            'type' => ChatMessageType::TEXT,
            'status' => ChatMessageStatus::QUEUED,
            'body_ciphertext' => 'cipher-queued',
        ]);

        $calls = 0;
        $this->mock(ChatCrypto::class, function ($mock) use (&$calls) {
            $mock->shouldReceive('decrypt')->andReturnUsing(function () use (&$calls) {
                $calls++;

                return 'plain-page';
            });
        });

        $this->actingAs($admin);
        Livewire::test(ViewChatRoom::class, ['record' => $room->id])
            ->assertSuccessful()
            ->assertSee('plain-page')
            ->assertSee('Автор')
            ->assertDontSee('Queued Sender Unique')
            ->assertDontSee('cipher-queued');

        $this->assertGreaterThan(0, $calls);
        $this->assertLessThanOrEqual(50, $calls);
    }

    public function test_view_feed_shows_placeholder_when_decrypt_fails(): void
    {
        $admin = User::factory()->admin()->create();
        $room = $this->columnRoom($admin, '15');
        $plain = app(ChatCrypto::class)->encrypt('секрет');
        ChatMessage::create([
            'room_id' => $room->id,
            'sender_id' => $admin->id,
            'client_id' => (string) Str::uuid(),
            'type' => ChatMessageType::TEXT,
            'status' => ChatMessageStatus::AVAILABLE,
            'body_ciphertext' => $plain,
        ]);
        ChatMessage::create([
            'room_id' => $room->id,
            'sender_id' => $admin->id,
            'client_id' => (string) Str::uuid(),
            'type' => ChatMessageType::TEXT,
            'status' => ChatMessageStatus::AVAILABLE,
            'body_ciphertext' => 'not-an-envelope',
        ]);

        $this->actingAs($admin);
        Livewire::test(ViewChatRoom::class, ['record' => $room->id])
            ->assertSuccessful()
            ->assertSee('секрет')
            ->assertSee('не удалось расшифровать');
    }

    public function test_column_is_locked_on_edit_for_column_rooms(): void
    {
        $admin = User::factory()->admin()->create();
        $room = $this->columnRoom($admin, '15');
        $this->actingAs($admin);

        Livewire::test(EditChatRoom::class, ['record' => $room->id])
            ->assertFormFieldIsDisabled('column')
            ->fillForm([
                'title' => 'Колонна 16',
                'column' => '16',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $room->refresh();
        $this->assertSame('15', $room->column);
        $this->assertSame('Колонна 16', $room->title);
    }

    public function test_title_only_save_keeps_inactive_regular_members(): void
    {
        $admin = User::factory()->admin()->create();
        $instructor = $this->instructor('15', 'Иванов');
        $this->actingAs($admin);

        Livewire::test(CreateChatRoom::class)
            ->fillForm([
                'title' => 'Колонна 15',
                'type' => ChatType::COLUMN->value,
                'column' => '15',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $room = ChatRoom::query()->firstOrFail();
        $instructor->update(['is_active' => false]);

        Livewire::test(EditChatRoom::class, ['record' => $room->id])
            ->fillForm(['title' => 'Колонна 15б'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('chat_members', [
            'room_id' => $room->id,
            'user_id' => $instructor->id,
            'source' => ChatMemberSource::REGULAR->value,
        ]);
        $this->assertSame('Колонна 15б', $room->fresh()->title);
    }

    public function test_soft_delete_keeps_ciphertext(): void
    {
        $admin = User::factory()->admin()->create();
        $room = $this->columnRoom($admin, '15');
        $cipher = app(ChatCrypto::class)->encrypt('тайна');
        $message = ChatMessage::create([
            'room_id' => $room->id,
            'sender_id' => $admin->id,
            'client_id' => (string) Str::uuid(),
            'type' => ChatMessageType::TEXT,
            'status' => ChatMessageStatus::AVAILABLE,
            'body_ciphertext' => $cipher,
        ]);

        $this->actingAs($admin);
        Livewire::test(ViewChatRoom::class, ['record' => $room->id])
            ->call('deleteMessage', $message->id)
            ->assertSuccessful();

        $message->refresh();
        $this->assertNotNull($message->deleted_at);
        $this->assertSame($admin->id, $message->deleted_by);
        $this->assertSame($cipher, $message->body_ciphertext);
        $this->assertDatabaseMissing('action_logs', ['action' => 'chat.message.deleted']);
    }

    private function instructor(string $column, string $name, bool $active = true): User
    {
        $user = User::factory()->instructor()->create([
            'name' => $name,
            'is_active' => $active,
        ]);
        UserProfile::create(['user_id' => $user->id, 'column' => $column]);

        return $user->fresh();
    }

    private function columnRoom(User $creator, string $column): ChatRoom
    {
        return ChatRoom::create([
            'type' => ChatType::COLUMN,
            'title' => 'Колонна '.$column,
            'column' => $column,
            'created_by' => $creator->id,
        ]);
    }
}
