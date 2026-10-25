<?php

namespace Tests\Feature;

use App\Enums\ChatMemberRole;
use App\Enums\ChatMemberSource;
use App\Enums\ChatMessageStatus;
use App\Enums\ChatType;
use App\Jobs\ProcessChatAttachment;
use App\Models\ChatAttachment;
use App\Models\ChatMember;
use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\Antivirus\ClamAvScanner;
use App\Services\Chat\ChatCrypto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

class ChatAttachmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        if (! getenv('APP_KEY')) {
            $key = 'base64:AQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQE=';
            putenv('APP_KEY='.$key);
            $_ENV['APP_KEY'] = $key;
            $_SERVER['APP_KEY'] = $key;
        }
        parent::setUp();
        config([
            'chat.enabled' => true,
            'chat.pilot_column' => null,
            'chat.timezone' => 'Europe/Moscow',
        ]);
        Storage::fake('s3');
        Storage::fake('local');
        Bus::fake();
        $this->mock(ClamAvScanner::class, function ($mock) {
            $mock->shouldNotReceive('scan');
        });
    }

    public function test_first_attachment_is_202_queued_without_scanning_or_minio(): void
    {
        $user = $this->instructor();
        $room = $this->columnRoom(User::factory()->admin()->create(), '15');
        $this->member($room, $user, ChatMemberRole::MODERATOR);
        $file = $this->jpeg('photo.jpg');
        $clientId = (string) Str::uuid();

        $response = $this->upload($user, $room, [
            'client_id' => $clientId,
            'type' => 'image',
            'body' => 'подпись',
        ], $file)->assertAccepted()->assertJsonPath('client_id', $clientId)->assertJsonPath('status', 'queued');

        $id = (int) $response->json('id');
        $this->assertDatabaseHas('chat_messages', [
            'id' => $id,
            'status' => ChatMessageStatus::QUEUED->value,
            'type' => 'image',
            'client_id' => $clientId,
        ]);
        $this->assertNull(ChatMessage::query()->findOrFail($id)->body_ciphertext);
        $this->assertDatabaseCount('chat_attachments', 0);
        Storage::disk('local')->assertExists('chat-pending/'.$room->id.'/'.$id);
        Storage::disk('s3')->assertDirectoryEmpty('/');
        Bus::assertDispatched(ProcessChatAttachment::class, function (ProcessChatAttachment $job) use ($id, $room) {
            return $job->messageId === $id
                && $job->pendingRelativePath === 'chat-pending/'.$room->id.'/'.$id
                && $job->connection === 'database'
                && $job->queue === 'chat'
                && $job->tries === 3
                && $job->timeout === 90
                && $job->afterCommit === true;
        });
        Bus::assertDispatchedTimes(ProcessChatAttachment::class, 1);
    }

    public function test_replay_same_fingerprint_is_200_without_a_second_job(): void
    {
        $user = $this->instructor();
        $room = $this->columnRoom(User::factory()->admin()->create(), '15');
        $this->member($room, $user, ChatMemberRole::MODERATOR);
        $bytes = $this->jpegBytes();
        $clientId = (string) Str::uuid();

        $first = $this->upload($user, $room, [
            'client_id' => $clientId,
            'type' => 'image',
        ], $this->jpegFrom($bytes))->assertAccepted();

        $this->upload($user, $room, [
            'client_id' => $clientId,
            'type' => 'image',
        ], $this->jpegFrom($bytes))
            ->assertOk()
            ->assertJsonPath('id', $first->json('id'))
            ->assertJsonPath('status', 'queued')
            ->assertJsonPath('client_id', $clientId);

        Bus::assertDispatchedTimes(ProcessChatAttachment::class, 1);
        $this->assertSame(1, ChatMessage::query()->where('room_id', $room->id)->count());
    }

    public function test_same_client_id_with_different_payload_is_409(): void
    {
        $user = $this->instructor();
        $room = $this->columnRoom(User::factory()->admin()->create(), '15');
        $this->member($room, $user, ChatMemberRole::MODERATOR);
        $clientId = (string) Str::uuid();

        $this->upload($user, $room, [
            'client_id' => $clientId,
            'type' => 'image',
            'body' => 'одна',
        ], $this->jpeg('a.jpg'))->assertAccepted();

        $this->upload($user, $room, [
            'client_id' => $clientId,
            'type' => 'image',
            'body' => 'другая',
        ], $this->jpeg('b.jpg'))
            ->assertStatus(409)
            ->assertJsonPath('message', 'Этот client_id уже использован с другим содержимым.');

        Bus::assertDispatchedTimes(ProcessChatAttachment::class, 1);
    }

    public function test_chat_disabled_returns_404_on_attachment_post_and_file_route(): void
    {
        $user = $this->instructor();
        $room = $this->columnRoom(User::factory()->admin()->create(), '15');
        $this->member($room, $user, ChatMemberRole::MODERATOR);
        Storage::disk('s3')->put('chat/1/2026/10/file.jpg', 'bytes');
        $attachment = $this->availableAttachment($room, $user, 'chat/1/2026/10/file.jpg');
        $url = URL::temporarySignedRoute('mobile.files.chats', now()->addMinutes(15), $attachment->id);

        config(['chat.enabled' => false]);

        $this->upload($user, $room, [
            'client_id' => (string) Str::uuid(),
            'type' => 'image',
        ], $this->jpeg())->assertNotFound();

        $this->get($url)->assertNotFound();
        Bus::assertNothingDispatched();
    }

    public function test_archived_room_rejects_attachment_with_410(): void
    {
        $user = $this->instructor();
        $room = $this->columnRoom(User::factory()->admin()->create(), '15');
        $this->member($room, $user, ChatMemberRole::MODERATOR);
        $room->archived_at = now();
        $room->save();

        $this->upload($user, $room, [
            'client_id' => (string) Str::uuid(),
            'type' => 'pdf',
        ], UploadedFile::fake()->create('scan.pdf', 20, 'application/pdf'))
            ->assertStatus(410)
            ->assertJsonPath('message', 'Чат архивирован.');

        Bus::assertNothingDispatched();
    }

    public function test_invalid_mime_is_422_on_file(): void
    {
        $user = $this->instructor();
        $room = $this->columnRoom(User::factory()->admin()->create(), '15');
        $this->member($room, $user, ChatMemberRole::MODERATOR);

        $this->upload($user, $room, [
            'client_id' => (string) Str::uuid(),
            'type' => 'image',
        ], UploadedFile::fake()->image('sticker.gif', 8, 8))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file']);

        Bus::assertNothingDispatched();
    }

    public function test_signed_file_route_streams_without_membership_recheck(): void
    {
        $user = $this->instructor();
        $room = $this->columnRoom(User::factory()->admin()->create(), '15');
        $this->member($room, $user, ChatMemberRole::MODERATOR);
        Storage::disk('s3')->put('chat/10/2026/10/pic.jpg', 'jpeg-bytes');
        $attachment = $this->availableAttachment($room, $user, 'chat/10/2026/10/pic.jpg');
        $url = URL::temporarySignedRoute('mobile.files.chats', now()->addMinutes(15), $attachment->id);

        $stranger = User::factory()->driver()->create();
        $response = $this->actingAs($stranger)->get($url);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/jpeg');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('jpeg-bytes', $response->streamedContent());
        $this->get('/api/mobile/files/chats/'.$attachment->id)->assertForbidden();
    }

    public function test_admin_serve_chat_attachment_is_admin_only_and_signed(): void
    {
        Storage::disk('s3')->put('chat/11/2026/10/doc.pdf', '%PDF-1.4 chat');
        $url = URL::temporarySignedRoute(
            'admin.chat-attachments.serve',
            now()->addMinutes(15),
            ['path' => 'chat/11/2026/10/doc.pdf'],
        );

        $this->actingAs(User::factory()->driver()->create())
            ->get($url)
            ->assertForbidden();

        $response = $this->actingAs(User::factory()->admin()->create())->get($url);
        $response->assertOk();
        $response->assertHeader('Content-Disposition', 'inline; filename="doc.pdf"');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('%PDF-1.4 chat', $response->streamedContent());
    }

    public function test_worker_success_is_visible_on_messages_with_signed_file_url(): void
    {
        $user = $this->instructor();
        $room = $this->columnRoom(User::factory()->admin()->create(), '15');
        $this->member($room, $user, ChatMemberRole::MODERATOR);
        $bytes = $this->jpegBytes();

        $response = $this->upload($user, $room, [
            'client_id' => (string) Str::uuid(),
            'type' => 'image',
            'body' => 'вид с пути',
        ], $this->jpegFrom($bytes))->assertAccepted();

        $job = null;
        Bus::assertDispatched(ProcessChatAttachment::class, function (ProcessChatAttachment $dispatched) use (&$job) {
            $job = $dispatched;

            return true;
        });
        $this->assertInstanceOf(ProcessChatAttachment::class, $job);

        $scanner = \Mockery::mock(ClamAvScanner::class);
        $scanner->shouldReceive('scan')->once();
        $job->handle($scanner, app(ChatCrypto::class));

        $row = ChatMessage::query()->with('attachment')->findOrFail($response->json('id'));
        $this->assertSame(ChatMessageStatus::AVAILABLE, $row->status);
        $this->assertSame('вид с пути', app(ChatCrypto::class)->decrypt((string) $row->body_ciphertext));
        $this->assertNotNull($row->attachment);
        $this->assertTrue(str_starts_with($row->attachment->path, 'chat/'.$room->id.'/'));
        Storage::disk('s3')->assertExists($row->attachment->path);
        Storage::disk('local')->assertMissing('chat-pending/'.$room->id.'/'.$row->id);

        $feed = $this->api($user, 'get', '/chats/'.$room->id.'/messages')->assertOk();
        $this->assertSame($row->id, $feed->json('messages.0.id'));
        $this->assertSame('вид с пути', $feed->json('messages.0.body'));
        $this->assertSame($row->attachment->id, $feed->json('messages.0.file.id'));
        $this->assertStringContainsString('/api/mobile/files/chats/'.$row->attachment->id, (string) $feed->json('messages.0.file.url'));
        $this->assertArrayNotHasKey('path', $feed->json('messages.0.file'));
    }

    private function instructor(string $column = '15'): User
    {
        $user = User::factory()->instructor()->create();
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

    private function availableAttachment(ChatRoom $room, User $user, string $path): ChatAttachment
    {
        $message = ChatMessage::create([
            'room_id' => $room->id,
            'sender_id' => $user->id,
            'client_id' => (string) Str::uuid(),
            'payload_fingerprint' => hash('sha256', "image\n\nabc"),
            'type' => 'image',
            'status' => ChatMessageStatus::AVAILABLE,
        ]);

        return ChatAttachment::create([
            'message_id' => $message->id,
            'path' => $path,
            'original_name' => 'photo.jpg',
            'mime' => 'image/jpeg',
            'size' => 10,
            'sha256' => hash('sha256', 'bytes'),
        ]);
    }

    private function jpeg(string $name = 'photo.jpg'): UploadedFile
    {
        return UploadedFile::fake()->image($name, 12, 12);
    }

    private function jpegBytes(): string
    {
        $file = $this->jpeg();

        return $file->get();
    }

    private function jpegFrom(string $bytes, string $name = 'photo.jpg'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $bytes)->mimeType('image/jpeg');
    }

    private function upload(User $user, ChatRoom $room, array $fields, UploadedFile $file)
    {
        $this->app['auth']->forgetGuards();
        $token = $user->createToken('mobile')->plainTextToken;

        return $this->withToken($token)
            ->withHeaders(['Accept' => 'application/json'])
            ->post(
                '/api/mobile/chats/'.$room->id.'/messages',
                array_merge($fields, ['file' => $file]),
            );
    }

    private function api(User $user, string $method, string $path, array $data = [])
    {
        $this->app['auth']->forgetGuards();
        $token = $user->createToken('mobile')->plainTextToken;

        return $this->withToken($token)->json($method, '/api/mobile'.$path, $data);
    }
}
