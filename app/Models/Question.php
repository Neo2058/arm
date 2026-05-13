<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Question extends Model
{
    protected $fillable = ['quiz_id', 'question_text', 'sort_order'];

    public function quiz() { return $this->belongsTo(Quiz::class); }
    public function answers() { return $this->hasMany(Answer::class); }
}
