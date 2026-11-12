<?php

namespace App\Models;

use App\Services\DocumentCatalogAudit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class DocumentFolder extends Model
{
    public const CATEGORIES = [
        'manual' => 'Руководства по эксплуатации', 'order' => 'Приказы и распоряжения по депо',
        'orderForMetro' => 'Приказы по Метрополитену', 'instruction' => 'Инструкции по Депо',
        'instForMetro' => 'Инструкции по Метрополитену', 'technical' => 'Конспекты УПЦ',
        'student' => 'Конспект ТУ', 'other' => 'Прочие материалы', 'remember' => 'Памятки',
    ];

    protected $fillable = ['name', 'category', 'parent_id'];

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    protected static function booted(): void
    {
        static::created(fn (self $folder) => DocumentCatalogAudit::saved($folder, 'document_folder.saved', true));
        static::updated(fn (self $folder) => DocumentCatalogAudit::saved($folder, 'document_folder.saved'));
        static::saving(function (self $folder) {
            // Immutable links make cycles impossible, including concurrent edits.
            if ($folder->exists && ($folder->isDirty('parent_id') || $folder->isDirty('category'))) {
                throw ValidationException::withMessages(['parent_id' => 'Родитель и основная категория существующей папки не изменяются.']);
            }
            if (! isset(self::CATEGORIES[$folder->category]) || trim($folder->name) === '') {
                throw ValidationException::withMessages(['name' => 'Укажите название и действующую категорию.']);
            }
            $depth = 1;
            $parent = $folder->parent_id ? self::find($folder->parent_id) : null;
            if ($folder->parent_id && ! $parent) {
                throw ValidationException::withMessages(['parent_id' => 'Родительская папка не найдена.']);
            }
            while ($parent) {
                if ($parent->category !== $folder->category || ++$depth > 6 || $parent->id === $folder->id) {
                    throw ValidationException::withMessages(['parent_id' => 'Выберите родителя той же категории; допускается до 6 уровней.']);
                }
                $parent = $parent->parent;
            }
        });
    }

    public function path(): array
    {
        $path = [];
        $node = $this;
        $seen = [];
        while ($node && count($path) < 6 && ! isset($seen[$node->id])) {
            $seen[$node->id] = true;
            array_unshift($path, ['id' => $node->id, 'name' => $node->name]);
            $node = $node->parent;
        }

        return $path;
    }
}
