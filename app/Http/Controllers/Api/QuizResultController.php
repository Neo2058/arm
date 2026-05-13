<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\QuizResult;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class QuizResultController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'quiz_id' => 'required|exists:quizzes,id',
            'score' => 'required|integer',
            'total_questions' => 'required|integer',
            'time_spent' => 'required|integer',
            'answers_log' => 'nullable|array',
        ]);

        $result = QuizResult::create([
            'user_id' => Auth::id(),
            'quiz_id' => $data['quiz_id'],
            'score' => $data['score'],
            'total_questions' => $data['total_questions'],
            'time_spent' => $data['time_spent'],
            'answers_log' => $data['answers_log'],
        ]);

        // Здесь же можно отправить данные в ClickHouse для аналитики
        //AnalyticsService::logQuizDone($result);

        return response()->json(['status' => 'success', 'result_id' => $result->id]);
    }
}
