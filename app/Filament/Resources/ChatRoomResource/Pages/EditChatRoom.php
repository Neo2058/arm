<?php

namespace App\Filament\Resources\ChatRoomResource\Pages;

use App\Enums\ChatMemberSource;
use App\Filament\Resources\ChatRoomResource;
use App\Models\User;
use App\Services\Chat\ChatService;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditChatRoom extends EditRecord
{
    protected static string $resource = ChatRoomResource::class;

    public function getSubheading(): ?string
    {
        return ChatRoomResource::SUBTITLE;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['archived'] = $this->record?->archived_at !== null;
        $data['members'] = $this->record?->members()
            ->where('source', ChatMemberSource::REGULAR)
            ->orderBy('id')
            ->get()
            ->map(fn ($member) => [
                'user_id' => $member->user_id,
                'role' => $member->role->value,
            ])
            ->all() ?? [];

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $members = $data['members'] ?? [];
        unset($data['members']);

        /** @var User $actor */
        $actor = auth()->user();

        return app(ChatService::class)->updateRoom(
            $record,
            $actor,
            $data,
            is_array($members) ? $members : [],
        );
    }
}
