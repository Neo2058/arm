<?php

namespace App\Filament\Resources\ChatRoomResource\Pages;

use App\Enums\ChatMessageStatus;
use App\Enums\ChatMessageType;
use App\Filament\Resources\ChatRoomResource;
use App\Models\ChatMessage;
use App\Services\Chat\ChatCrypto;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\WithPagination;
use RuntimeException;

class ViewChatRoom extends ViewRecord
{
    use WithPagination;

    protected static string $resource = ChatRoomResource::class;

    protected static string $view = 'filament.resources.chat-room-resource.pages.view-chat-room';

    public function getSubheading(): ?string
    {
        return ChatRoomResource::SUBTITLE;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }

    /**
     * @return LengthAwarePaginator<int, ChatMessage>
     */
    public function getFeed(): LengthAwarePaginator
    {
        $feed = ChatMessage::query()
            ->where('room_id', $this->getRecord()->id)
            ->where('status', '!=', ChatMessageStatus::QUEUED)
            ->with('sender:id,name')
            ->orderByDesc('id')
            ->paginate(50);

        foreach ($feed as $message) {
            $message->setAttribute('feed_body', $this->presentFeedBody($message));
        }

        return $feed;
    }

    public function deleteMessage(int $messageId): void
    {
        $message = ChatMessage::query()
            ->where('room_id', $this->getRecord()->id)
            ->whereKey($messageId)
            ->firstOrFail();
        if ($message->deleted_at !== null) {
            return;
        }

        $message->deleted_at = now();
        $message->deleted_by = auth()->id();
        $message->save();
    }

    private function presentFeedBody(ChatMessage $message): string
    {
        if ($message->status === ChatMessageStatus::REJECTED) {
            return 'Отклонено антивирусом';
        }

        if ($message->type === ChatMessageType::IMAGE) {
            return 'Фото';
        }
        if ($message->type === ChatMessageType::PDF) {
            return 'PDF';
        }

        $cipher = $message->body_ciphertext;
        if (! is_string($cipher) || $cipher === '') {
            return '';
        }

        try {
            return app(ChatCrypto::class)->decrypt($cipher);
        } catch (DecryptException|RuntimeException) {
            return 'не удалось расшифровать';
        }
    }
}
