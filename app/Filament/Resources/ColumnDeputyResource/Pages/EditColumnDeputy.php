<?php

namespace App\Filament\Resources\ColumnDeputyResource\Pages;

use App\Filament\Resources\ColumnDeputyResource;
use App\Models\ColumnDeputy;
use App\Services\Chat\ChatService;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditColumnDeputy extends EditRecord
{
    protected static string $resource = ColumnDeputyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->using(function (ColumnDeputy $record): void {
                    app(ChatService::class)->revoke($record);
                }),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var ColumnDeputy $record */
        $updated = app(ChatService::class)->updateWindow(
            $record,
            $data['starts_on'],
            $data['ends_on'],
        );

        $reason = $data['reason'] ?? null;
        if ($updated->reason !== $reason) {
            $updated->reason = $reason;
            $updated->save();
        }

        return $updated;
    }
}
