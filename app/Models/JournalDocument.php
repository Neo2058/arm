<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JournalDocument extends Model
{
    protected $fillable = [
        'user_id', 'column', 'title', 'file_path', 'extracted_text'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
