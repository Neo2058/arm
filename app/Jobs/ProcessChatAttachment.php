<?php

namespace App\Jobs;

use App\Enums\ChatMessageStatus;
use App\Models\ChatAttachment;
use App\Models\ChatMessage;
use App\Services\Antivirus\ClamAvScanner;
use App\Services\Chat\ChatCrypto;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProcessChatAttachment implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 90; // ClamAV scan_timeout 60s + запас

    public function __construct(
        public int $messageId,
        public string $pendingRelativePath,
        public string $sha256,
    ) {
        $this->onConnection('database'); // иначе arm-app deferred выполнит скан в fpm
        $this->onQueue('chat');
        $this->afterCommit();
    }

    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(ClamAvScanner $scanner, ChatCrypto $crypto): void
    {
        $message = ChatMessage::query()->with('room')->find($this->messageId);
        if ($message === null) {
            $this->unlinkPending();

            return;
        }
        if ($message->status !== ChatMessageStatus::QUEUED) {
            return;
        }

        $relative = str_replace('\\', '/', $this->pendingRelativePath);
        if (! str_starts_with($relative, 'chat-pending/') || str_contains($relative, '..')) {
            $this->markRejected($message);

            return;
        }

        $disk = Storage::disk('local');
        if (! $disk->exists($relative)) {
            throw new \RuntimeException('Pending chat attachment is missing.');
        }

        $absolute = $disk->path($relative);
        $actualSha = hash_file('sha256', $absolute);
        if (! is_string($actualSha) || ! hash_equals($this->sha256, $actualSha)) {
            $this->markRejected($message);

            return;
        }

        $meta = $this->meta($disk, $relative);
        $originalName = $this->safeName((string) ($meta['original_name'] ?? 'file'));
        $mime = (string) ($meta['mime'] ?? 'application/octet-stream');
        $size = (int) ($meta['size'] ?? $disk->size($relative));
        $caption = (string) ($meta['caption'] ?? '');

        $uploaded = new UploadedFile($absolute, $originalName, $mime, UPLOAD_ERR_OK, true);
        try {
            $scanner->scan($uploaded, 'file');
        } catch (ValidationException) {
            $this->markRejected($message);

            return;
        }

        $object = $this->objectPath((int) $message->room_id, $originalName, $mime);
        $stream = fopen($absolute, 'rb');
        try {
            abort_unless(
                Storage::disk('s3')->put($object, $stream, ['visibility' => 'private']),
                503,
                'Хранилище недоступно.',
            );
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        DB::transaction(function () use ($message, $object, $originalName, $mime, $size, $caption, $crypto) {
            $locked = ChatMessage::query()->whereKey($message->id)->lockForUpdate()->first();
            if ($locked === null || $locked->status !== ChatMessageStatus::QUEUED) {
                return;
            }

            ChatAttachment::query()->firstOrCreate(
                ['message_id' => $locked->id],
                [
                    'path' => $object,
                    'original_name' => $originalName,
                    'mime' => $mime,
                    'size' => $size,
                    'sha256' => $this->sha256,
                ],
            );

            if ($caption !== '') {
                $locked->body_ciphertext = $crypto->encrypt($caption);
            }
            $locked->status = ChatMessageStatus::AVAILABLE;
            $locked->save();
        });

        $this->unlinkPending();
    }

    public function failed(?\Throwable $e): void
    {
        $message = ChatMessage::query()->find($this->messageId);
        if ($message !== null && $message->status === ChatMessageStatus::QUEUED) {
            $this->markRejected($message);

            return;
        }

        $this->unlinkPending();
    }

    /**
     * @return array<string, mixed>
     */
    private function meta($disk, string $relative): array
    {
        $metaPath = $relative.'.meta';
        if (! $disk->exists($metaPath)) {
            return [];
        }

        $decoded = json_decode((string) $disk->get($metaPath), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function objectPath(int $roomId, string $originalName, string $mime): string
    {
        $ext = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
        if ($ext === '') {
            $ext = match ($mime) {
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'application/pdf' => 'pdf',
                default => 'bin',
            };
        }

        return 'chat/'.$roomId.'/'.now()->format('Y').'/'.now()->format('m').'/'.Str::uuid().'.'.$ext;
    }

    private function safeName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        if ($name === '' || mb_strlen($name) > 255) {
            return 'file';
        }

        return $name;
    }

    private function markRejected(ChatMessage $message): void
    {
        if ($message->status === ChatMessageStatus::QUEUED) {
            $message->status = ChatMessageStatus::REJECTED;
            $message->save();
        }
        $this->unlinkPending();
    }

    private function unlinkPending(): void
    {
        $relative = str_replace('\\', '/', $this->pendingRelativePath);
        if (! str_starts_with($relative, 'chat-pending/') || str_contains($relative, '..')) {
            return;
        }

        Storage::disk('local')->delete([$relative, $relative.'.meta']);
    }
}
