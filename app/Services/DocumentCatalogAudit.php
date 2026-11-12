<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class DocumentCatalogAudit
{
    public static function saved(Model $record, string $event, bool $created = false): void
    {
        $actor = auth()->user();
        if (! $actor) {
            return;
        }
        $fields = array_values(array_diff(array_keys($created ? $record->getAttributes() : $record->getChanges()), ['created_at', 'updated_at']));
        if ($fields === []) {
            return;
        }
        $id = (int) $record->getKey();
        DB::afterCommit(function () use ($event, $id, $fields, $actor) {
            try {
                ClickHouseService::log($event, $id, ['fields' => $fields], $actor);
            } catch (\Throwable $exception) {
                report($exception);
            }
        });
    }
}
