<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;


class Document extends Model
{
    protected $fillable = [
        'title',
        'file_path',
        'category',
        'size',
        'allowed_roles'];

    protected $casts = [
        'allowed_roles' => 'array', // Автоматически превращает JSON из базы в PHP-массив
    ];

    public function quiz(): HasOne
    {
        return $this->hasOne(Quiz::class);
    }
}
