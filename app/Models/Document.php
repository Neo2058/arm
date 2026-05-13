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
    ];

    public function quiz(): HasOne
    {
        return $this->hasOne(Quiz::class);
    }
}
