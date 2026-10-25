<?php

namespace App\Filament\Resources\ColumnDeputyResource\Pages;

use App\Filament\Resources\ColumnDeputyResource;
use App\Models\User;
use App\Services\Chat\ChatService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateColumnDeputy extends CreateRecord
{
    protected static string $resource = ColumnDeputyResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        /** @var User $actor */
        $actor = auth()->user();
        $user = User::query()->findOrFail($data['user_id']);

        return app(ChatService::class)->grant(
            $actor,
            $user,
            (string) $data['column'],
            $data['starts_on'],
            $data['ends_on'],
            $data['reason'] ?? null,
        );
    }
}
