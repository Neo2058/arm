<?php

namespace App\Filament\Resources\DocumentResource\Pages;

use App\Filament\Resources\DocumentResource;
use App\Services\DocumentPublishing;
use App\Services\DocumentRevisions;
use Filament\Actions;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class EditDocument extends EditRecord
{
    use Concerns\ValidatesDocumentPublishing;

    protected static string $resource = DocumentResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return $this->withDocumentErrors(fn () => app(DocumentPublishing::class)->save($data, $record));
    }

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

    protected function getFormActions(): array
    {
        return $this->record->withdrawn_at ? [] : parent::getFormActions();
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('history')->label('История редакций')
                ->url(fn () => DocumentResource::getUrl('index', ['tableFilters' => [
                    'family' => ['value' => $this->record->root_document_id ?: $this->record->id],
                    'edition_status' => ['value' => 'all'],
                ]])),
            Actions\Action::make('revision')->label('Новая редакция')
                ->visible(fn () => ! $this->record->nextEdition()->exists())
                ->url(fn () => DocumentResource::getUrl('create', ['source' => $this->record->id])),
            Actions\Action::make('withdraw')->label('Вывести из действия')->color('danger')
                ->visible(fn () => ! $this->record->withdrawn_at)
                ->form([
                    Textarea::make('reason')->label('Основание вывода')->required()->maxLength(2000),
                    Checkbox::make('confirmed')->label('Убрать из действующих документов. Оригинал и росписи сохранятся.')->accepted()->required(),
                ])
                ->action(function (array $data) {
                    app(DocumentRevisions::class)->withdraw(auth()->user(), $this->record->id, $data);
                    $this->redirect(DocumentResource::getUrl('edit', ['record' => $this->record->id]));
                }),
        ];
    }
}
