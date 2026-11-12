<?php

namespace App\Filament\Resources\DocumentResource\Pages;

use App\Filament\Resources\DocumentResource;
use App\Services\DocumentPublishing;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateDocument extends CreateRecord
{
    use Concerns\ValidatesDocumentPublishing;

    protected static string $resource = DocumentResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return $this->withDocumentErrors(fn () => app(DocumentPublishing::class)->save($data));
    }
}
