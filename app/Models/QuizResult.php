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
        'answers_log',
        'is_viewed'
    ];

    protected $casts = [
        'answers_log' => 'array',
        'is_viewed' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function quiz()
    {
        return $this->belongsTo(Quiz::class);
    }

    /**
     * Формирует детализированный отчет по каждому вопросу для админки
     */
    public function getDetailedReportAttribute(): array
    {
        if (!$this->answers_log || !$this->quiz) {
            return [];
        }

        $report = [];
        // Загружаем все вопросы и ответы этого теста для сопоставления
        $questions = $this->quiz->questions()->with('answers')->get();

        foreach ($questions as $question) {
            $userAnswerId = $this->answers_log[$question->id] ?? null;

            $userAnswerText = 'Нет ответа';
            $correctAnswerText = 'Не указан';

            foreach ($question->answers as $answer) {
                if ($answer->is_correct) {
                    $correctAnswerText = $answer->answer_text;
                }
                if ($answer->id == $userAnswerId) {
                    $userAnswerText = $answer->answer_text;
                }
            }

            $report[] = [
                'question' => $question->question_text,
                'user_answer' => $userAnswerText,
                'correct_answer' => $correctAnswerText,
                'is_right' => $userAnswerText === $correctAnswerText,
            ];
        }

        return $report;
    }
}
