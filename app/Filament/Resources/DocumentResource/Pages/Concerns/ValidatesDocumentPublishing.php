<?php

namespace App\Filament\Resources\DocumentResource\Pages\Concerns;

use App\Services\DocumentPublishing;
use Illuminate\Validation\ValidationException;

trait ValidatesDocumentPublishing
{
    protected function beforeValidate(): void
    {
        $this->withDocumentErrors(fn () => app(DocumentPublishing::class)->indexLegacy());
    }

    protected function withDocumentErrors(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (ValidationException $error) {
            $errors = [];
            foreach ($error->errors() as $field => $messages) {
                $errors['data.'.$field] = $messages;
            }
            throw ValidationException::withMessages($errors);
        }
    }
}
