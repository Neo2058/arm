<?php

namespace App\Filament\Resources\DocumentResource\Pages;

use App\Filament\Resources\DocumentResource;
use App\Models\Document;
use App\Services\DocumentPublishing;
use App\Services\DocumentRevisions;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Locked;

class CreateDocument extends CreateRecord
{
    use Concerns\ValidatesDocumentPublishing;

    #[Locked]
    public ?int $sourceDocumentId = null;

    public function mount(): void
    {
        parent::mount();
        $id = request()->integer('source');
        if ($id > 0) {
            $source = Document::findOrFail($id);
            abort_if($source->nextEdition()->exists(), 409, 'Редакция уже сменилась. Откройте актуальную карточку.');
            $this->sourceDocumentId = $source->id;
            $this->form->fill(array_merge($source->only(['title', 'category', 'document_folder_id', 'document_number', 'publication_year', 'allowed_roles', 'instruction_category_id']), [
                'revision_label' => (string) ($source->revision_number + 1),
                'effective_on' => today()->toDateString(),
                'file_path' => null,
            ]));
        }
    }

    public function getTitle(): string
    {
        return $this->sourceDocumentId ? 'Новая редакция документа' : 'Создать документ';
    }

    protected static string $resource = DocumentResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        if ($this->sourceDocumentId) {
            return $this->withDocumentErrors(fn () => app(DocumentRevisions::class)->publish(auth()->user(), $this->sourceDocumentId, $data));
        }

        return $this->withDocumentErrors(fn () => app(DocumentPublishing::class)->save($data));
    }
}
