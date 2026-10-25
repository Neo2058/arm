<?php

namespace App\Filament\Resources\ChatRoomResource\Pages;

use App\Filament\Resources\ChatRoomResource;
use App\Models\User;
use App\Services\Chat\ChatService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateChatRoom extends CreateRecord
{
    protected static string $resource = ChatRoomResource::class;

    public function getSubheading(): ?string
    {
        return ChatRoomResource::SUBTITLE;
    }

    protected function handleRecordCreation(array $data): Model
    {
        $members = $data['members'] ?? [];
        unset($data['members']);

        /** @var User $actor */
        $actor = auth()->user();

        return app(ChatService::class)->createRoom($actor, $data, is_array($members) ? $members : []);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
