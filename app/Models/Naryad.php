<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Naryad extends Model
{
    protected $fillable = [
        'title',
        'file_path',
        'source_files',
        'work_shifts_path',
        'weekend_shifts_path',
        'naryad_date',
        'uploaded_by',
        'allowed_roles',
    ];

    protected $casts = [
        'allowed_roles' => 'array',
        'source_files' => 'array',
        'naryad_date' => 'date',
    ];

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function personFiles(): HasMany
    {
        return $this->hasMany(NaryadPersonFile::class);
    }

    /**
     * TXT-наряды для парсера searching. Старые записи держат текст в file_path.
     *
     * @return list<string>
     */
    public function sourceFilesForParse(): array
    {
        $files = array_values(array_filter($this->source_files ?? []));
        if ($files !== []) {
            return $files;
        }
        if ($this->file_path && ! $this->hasGeneralPdf()) {
            return [$this->file_path];
        }

        return [];
    }

    public function hasGeneralPdf(): bool
    {
        $path = strtolower((string) $this->file_path);

        return $path !== '' && str_ends_with($path, '.pdf');
    }
}
