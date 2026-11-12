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
        'revision_label',
        'effective_on',
        'allowed_roles',
        'instruction_category_id', // for briefings / rospisi
    ];

    protected $casts = [
        'withdrawn_at' => 'datetime',
        'effective_on' => 'date',
        'revision_number' => 'integer',
        'publication_year' => 'integer',
        'document_folder_id' => 'integer',
        'allowed_roles' => 'array', // Автоматически превращает JSON из базы в PHP-массив
    ];

    public function scopeActive($query)
    {
        return $query->whereNull('withdrawn_at')->where(fn ($q) => $q->whereNull('effective_on')->orWhereDate('effective_on', '<=', today()));
    }

    public function isActiveEdition(): bool
    {
        return ! $this->withdrawn_at && (! $this->effective_on || $this->effective_on->lte(today()));
    }

    public function nextEdition(): HasOne
    {
        return $this->hasOne(self::class, 'replaces_document_id');
    }

    public function previousEdition(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaces_document_id');
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(DocumentFolder::class, 'document_folder_id');
    }

    protected static function booted(): void
    {
        static::deleting(function () {
            throw ValidationException::withMessages(['document' => 'Удаление карточки запрещено: сохраните редакцию и историю ознакомления.']);
        });
        static::created(fn (self $document) => DocumentCatalogAudit::saved($document, 'document.catalog_saved', true));
        static::updated(fn (self $document) => DocumentCatalogAudit::saved($document, 'document.catalog_saved'));
        static::saving(function (self $document) {
            if ($document->exists && $document->isDirty('file_path')) {
                throw ValidationException::withMessages(['file_path' => 'Оригинал неизменяем: выпустите новую редакцию.']);
            }
            if ($document->exists && $document->getOriginal('withdrawn_at') && $document->isDirty()) {
                throw ValidationException::withMessages(['title' => 'Архивная редакция неизменяема.']);
            }

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
