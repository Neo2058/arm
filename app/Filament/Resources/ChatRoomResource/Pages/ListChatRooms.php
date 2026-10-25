<?php

namespace App\Filament\Resources\ChatRoomResource\Pages;

use App\Filament\Resources\ChatRoomResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListChatRooms extends ListRecords
{
    protected static string $resource = ChatRoomResource::class;

    public function getSubheading(): ?string
    {
        return ChatRoomResource::SUBTITLE;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
