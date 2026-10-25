<?php

namespace Tests\Feature;

use App\Enums\ChatMemberRole;
use App\Enums\ChatMessageStatus;
use App\Enums\ChatMessageType;
use App\Enums\ChatType;
use App\Jobs\ProcessChatAttachment;
use App\Models\ChatAttachment;
use App\Models\ChatMember;
use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\User;
use App\Services\Antivirus\ClamAvScanner;
use App\Services\Chat\ChatCrypto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ChatAttachmentJobTest extends TestCase
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
            'chat.timezone' => 'Europe/Moscow',
        ]);
        Storage::fake('s3');
        Storage::fake('local');
    }

    public function test_job_is_configured_for_database_chat_queue(): void
    {
        [$message, $relative, $sha] = $this->queued();
        $job = $this->job($message, $relative, $sha);

        $this->assertSame('database', $job->connection);
        $this->assertSame('chat', $job->queue);
        $this->assertSame(3, $job->tries);
        $this->assertSame(90, $job->timeout);
        $this->assertSame([10, 60], $job->backoff());
        $this->assertTrue($job->afterCommit);

        ProcessChatAttachment::dispatch(
            $message->id,
            $relative,
            $sha,
            'photo.jpg',
            'image/jpeg',
            (int) Storage::disk('local')->size($relative),
            '',
        );

        $this->assertGreaterThan(0, DB::table('jobs')->where('queue', 'chat')->count());
        $payload = json_decode((string) DB::table('jobs')->where('queue', 'chat')->value('payload'), true);
        $this->assertSame(ProcessChatAttachment::class, $payload['displayName'] ?? null);
        $this->assertSame(0, ChatAttachment::query()->count());
        $this->assertSame(ChatMessageStatus::QUEUED, $message->fresh()->status);
    }

    public function test_handle_clean_scan_makes_message_available_and_stores_minio_object(): void
    {
        [$message, $relative, $sha, $user] = $this->queued(caption: 'с пути');
        $scanner = \Mockery::mock(ClamAvScanner::class);
        $scanner->shouldReceive('scan')->once();

        $this->job($message, $relative, $sha, 'с пути')
            ->handle($scanner, app(ChatCrypto::class));

        $fresh = $message->fresh();
        $this->assertSame(ChatMessageStatus::AVAILABLE, $fresh->status);
        $this->assertSame('с пути', app(ChatCrypto::class)->decrypt((string) $fresh->body_ciphertext));
        $attachment = ChatAttachment::query()->where('message_id', $message->id)->first();
        $this->assertNotNull($attachment);
        $this->assertSame('photo.jpg', $attachment->original_name);
        $this->assertSame('image/jpeg', $attachment->mime);
        $this->assertSame($sha, $attachment->sha256);
        $this->assertTrue(str_starts_with($attachment->path, 'chat/'.$message->room_id.'/'));
        Storage::disk('s3')->assertExists($attachment->path);
        Storage::disk('local')->assertMissing($relative);
        $this->assertSame($user->id, $fresh->sender_id);
    }

    public function test_handle_virus_rejects_without_attachment_or_minio_object(): void
    {
        [$message, $relative, $sha] = $this->queued();
        $scanner = \Mockery::mock(ClamAvScanner::class);
        $scanner->shouldReceive('scan')->once()->andThrow(
            ValidationException::withMessages(['file' => 'Вложение отклонено антивирусом.']),
        );

        $this->job($message, $relative, $sha)
            ->handle($scanner, app(ChatCrypto::class));

        $this->assertSame(ChatMessageStatus::REJECTED, $message->fresh()->status);
        $this->assertSame(0, ChatAttachment::query()->count());
        $this->assertSame([], Storage::disk('s3')->allFiles());
        Storage::disk('local')->assertMissing($relative);
        $this->assertNull($message->fresh()->body_ciphertext);
    }

    public function test_scanner_unavailable_after_tries_rejects_via_failed(): void
    {
        [$message, $relative, $sha] = $this->queued();
        $scanner = \Mockery::mock(ClamAvScanner::class);
        $scanner->shouldReceive('scan')->once()->andThrow(
            new HttpException(503, 'Антивирус недоступен. Повторите отправку позже.'),
        );
        $job = $this->job($message, $relative, $sha);

        try {
            $job->handle($scanner, app(ChatCrypto::class));
            $this->fail('Scanner 503 must retry, not reject inside handle().');
        } catch (HttpException $e) {
            $this->assertSame(503, $e->getStatusCode());
        }

        $this->assertSame(ChatMessageStatus::QUEUED, $message->fresh()->status);
        $this->assertSame(0, ChatAttachment::query()->count());
        Storage::disk('local')->assertExists($relative);

        $job->failed(new HttpException(503, 'Антивирус недоступен. Повторите отправку позже.'));

        $this->assertSame(ChatMessageStatus::REJECTED, $message->fresh()->status);
        $this->assertSame(0, ChatAttachment::query()->count());
        $this->assertSame([], Storage::disk('s3')->allFiles());
        Storage::disk('local')->assertMissing($relative);
    }

    public function test_handle_skips_non_queued_and_unlinks_pending(): void
    {
        [$message, $relative, $sha] = $this->queued();
        $message->status = ChatMessageStatus::AVAILABLE;
        $message->save();

        $scanner = \Mockery::mock(ClamAvScanner::class);
        $scanner->shouldNotReceive('scan');

        $this->job($message, $relative, $sha)->handle($scanner, app(ChatCrypto::class));

        $this->assertSame(ChatMessageStatus::AVAILABLE, $message->fresh()->status);
        $this->assertSame(0, ChatAttachment::query()->count());
        $this->assertSame([], Storage::disk('s3')->allFiles());
        Storage::disk('local')->assertMissing($relative);
    }

    /**
     * @return array{0: ChatMessage, 1: string, 2: string, 3: User}
     */
    private function queued(string $caption = ''): array
    {
        $user = User::factory()->instructor()->create();
        $room = ChatRoom::create([
            'type' => ChatType::COLUMN,
            'title' => 'Колонна 15',
            'column' => '15',
            'created_by' => $user->id,
        ]);
        ChatMember::create([
            'room_id' => $room->id,
            'user_id' => $user->id,
            'role' => ChatMemberRole::MODERATOR,
            'source' => 'regular',
            'added_by' => $user->id,
        ]);

        $upload = UploadedFile::fake()->image('photo.jpg', 16, 16);
        $bytes = $upload->get();
        $sha = hash('sha256', $bytes);
        $message = ChatMessage::create([
            'room_id' => $room->id,
            'sender_id' => $user->id,
            'client_id' => (string) Str::uuid(),
            'payload_fingerprint' => hash('sha256', "image\n".$caption."\n".$sha),
            'type' => ChatMessageType::IMAGE,
            'status' => ChatMessageStatus::QUEUED,
        ]);
        $relative = 'chat-pending/'.$room->id.'/'.$message->id;
        Storage::disk('local')->put($relative, $bytes);

        return [$message, $relative, $sha, $user];
    }

    private function job(ChatMessage $message, string $relative, string $sha, string $caption = ''): ProcessChatAttachment
    {
        return new ProcessChatAttachment(
            $message->id,
            $relative,
            $sha,
            'photo.jpg',
            'image/jpeg',
            (int) Storage::disk('local')->size($relative),
            $caption,
        );
    }
}
