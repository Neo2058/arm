<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Quiz;
use App\Models\QuizResult;
use App\Services\ClickHouseService;
use App\Services\QuizService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class QuizResultController extends Controller
{
    public function store(Request $request, QuizService $quizzes)
    {
        $data = $request->validate([
            'quiz_id' => 'required|integer|exists:quizzes,id',
            'time_spent' => 'required|integer|min:0|max:2147483647',
            'answers_log' => 'present|array|max:10000',
            'answers_log.*' => 'required|integer|min:1',
        ]);

        // Older browser tabs may still send score/total_questions; never trust them.
        $grade = $quizzes->grade(Quiz::findOrFail($data['quiz_id']), $request->user(), $data['answers_log']);

        $result = QuizResult::create([
            'user_id' => Auth::id(),
            'quiz_id' => $data['quiz_id'],
            'score' => $grade['score'],
            'total_questions' => $grade['total_questions'],
            'time_spent' => $data['time_spent'],
            'answers_log' => $grade['answers_log'],
        ]);

        ClickHouseService::log('complete_quiz', $result->quiz_id, [
            'score' => (int)$result->score,
            'total_questions' => (int)$result->total_questions,
            // Явно передаем процент успеха для ClickHouse
            'percent' => (float)(($result->score / $result->total_questions) * 100)
        ]);

        return response()->json(['status' => 'success', 'result_id' => $result->id]);
    }
}
