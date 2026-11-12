<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class InstructionCategory extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'description',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::deleting(function (self $category) {
            if ($category->documents()->exists() || $category->userSignatures()->exists()) {
                throw ValidationException::withMessages(['name' => 'В папке есть документы или история росписей. Удаление запрещено.']);
            }
        });
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'instruction_category_id');
    }

    public function userSignatures(): HasMany
    {
        return $this->hasMany(UserInstructionSignature::class);
    }
}
