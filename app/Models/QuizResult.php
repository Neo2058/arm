<?php


namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuizResult extends Model
{
    protected $fillable = [
        'user_id',
        'quiz_id',
        'score',
        'total_questions',
        'time_spent',
        'answers_log'
    ];

    // Обязательно добавь это, чтобы Laravel сам конвертировал массив в JSON
    protected $casts = [
        'answers_log' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function quiz()
    {
        return $this->belongsTo(Quiz::class);
    }
}
