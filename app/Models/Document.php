<?php

namespace App\Models;

use App\Services\DocumentCatalogAudit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Validation\ValidationException;

class Document extends Model
{
    protected $fillable = [
        'title',
        'file_path',
        'category',
        'size',
        'document_folder_id',
        'document_number',
        'publication_year',
        'allowed_roles',
        'instruction_category_id', // for briefings / rospisi
    ];

    protected $casts = [
        'publication_year' => 'integer',
        'document_folder_id' => 'integer',
        'allowed_roles' => 'array', // Автоматически превращает JSON из базы в PHP-массив
    ];

    public function folder(): BelongsTo
    {
        return $this->belongsTo(DocumentFolder::class, 'document_folder_id');
    }

    protected static function booted(): void
    {
        static::created(fn (self $document) => DocumentCatalogAudit::saved($document, 'document.catalog_saved', true));
        static::updated(fn (self $document) => DocumentCatalogAudit::saved($document, 'document.catalog_saved'));
        static::saving(function (self $document) {
            if ($document->document_folder_id) {
                $folder = DocumentFolder::find($document->document_folder_id);
                if (! $folder || $folder->category !== ($document->category ?: 'other')) {
                    throw ValidationException::withMessages(['document_folder_id' => 'Папка должна принадлежать выбранной категории документа.']);
                }
            }
        });
    }

    public function quiz(): HasOne
    {
        return $this->hasOne(Quiz::class);
    }

    public function instructionCategory(): BelongsTo
    {
        return $this->belongsTo(InstructionCategory::class, 'instruction_category_id');
    }
}
