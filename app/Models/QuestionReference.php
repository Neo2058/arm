<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuestionReference extends Model
{
    protected $fillable = ['question_id', 'document_id', 'page_number', 'anchor_text'];

    public function document() { return $this->belongsTo(Document::class); }
}
