<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FormularTask extends Model
{
    protected $fillable = [
        'title',
        'description',
        'category',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function userLogs(): HasMany
    {
        return $this->hasMany(UserFormularLog::class);
    }
}
