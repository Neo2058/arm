<?php

namespace App\Filament\Resources\ChatRoomResource\Pages;

use App\Enums\ChatMessageStatus;
use App\Filament\Resources\ChatRoomResource;
use App\Models\ChatMessage;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\WithPagination;

class ViewChatRoom extends ViewRecord
{
    use WithPagination;

    protected static string $resource = ChatRoomResource::class;

    protected static string $view = 'filament.resources.chat-room-resource.pages.view-chat-room';

    /** @var array<int, string> */
    private array $presented = [];

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
        return ChatMessage::query()
            ->where('room_id', $this->getRecord()->id)
            ->where('status', '!=', ChatMessageStatus::QUEUED)
            ->with('sender:id,name')
            ->orderByDesc('id')
            ->paginate(50);
    }

    public function presentBody(ChatMessage $message): string
    {
        if (array_key_exists($message->id, $this->presented)) {
            return $this->presented[$message->id];
        }

        $body = ChatRoomResource::presentFeedBody($message);
        $this->presented[$message->id] = $body;

        return $body;
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
}
