<?php

namespace App\Filament\Resources\DocumentResource\Pages;

use App\Filament\Resources\DocumentResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Storage;

class EditDocument extends EditRecord
{
    protected static string $resource = DocumentResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        if (! empty($data['file_path'])) {
            try {
                if (! Storage::disk('s3')->exists($data['file_path'])) {
                    $data['file_path'] = null;
                }
            } catch (\Throwable $e) {
                \Log::warning('S3 exists check failed during form fill for document', [
                    'document' => $data['id'] ?? null,
                    'path' => $data['file_path'],
                    'error' => $e->getMessage(),
                ]);
                $data['file_path'] = null;
            }
        }

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
