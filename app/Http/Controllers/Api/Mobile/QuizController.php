<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\ActionLog;
use App\Models\Quiz;
use App\Models\QuizResult;
use App\Services\ClickHouseService;
use App\Services\QuizService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QuizController extends Controller
{
    public function __construct(private QuizService $quizzes) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $quizzes = Quiz::query()
            ->where('is_active', true)
            ->with('document')
            ->orderBy('title')
            ->get()
            ->filter(function (Quiz $quiz) use ($user) {
                if ($quiz->document_id && ! $quiz->document) {
                    return false;
                }

                return ! $quiz->document || $user->canAccessByRoles($quiz->document->allowed_roles);
            })
            ->values();

        $latest = QuizResult::query()
            ->where('user_id', $user->id)
            ->whereIn('quiz_id', $quizzes->pluck('id'))
            ->orderByDesc('id')
            ->get()
            ->unique('quiz_id')
            ->keyBy('quiz_id');

        return response()->json([
            'quizzes' => $quizzes->map(fn (Quiz $quiz) => [
                'id' => $quiz->id,
                'title' => $quiz->title,
                'description' => $quiz->description,
                'time_limit' => $quiz->time_limit,
                'document_title' => $quiz->document?->title,
                'general' => $quiz->document_id === null,
                'last_score' => $latest->get($quiz->id)?->score,
                'last_total' => $latest->get($quiz->id)?->total_questions,
            ]),
        ]);
    }

    public function show(Request $request, Quiz $quiz): JsonResponse
    {
        $player = $this->quizzes->playerData($quiz, $request->user(), 'mobile.files.documents');
        ActionLog::log('mobile.quiz_opened', [
            'quiz_id' => $quiz->id,
            'title' => $quiz->title,
        ]);

        return response()->json($player);
    }

    public function submit(Request $request, Quiz $quiz): JsonResponse
    {
        $data = $request->validate([
            'time_spent' => 'required|integer|min:0|max:2147483647',
            'answers_log' => 'present|array|max:10000',
            'answers_log.*' => 'required|integer|min:1',
        ]);

        $grade = $this->quizzes->grade($quiz, $request->user(), $data['answers_log']);

        $result = QuizResult::create([
            'user_id' => $request->user()->id,
            'quiz_id' => $quiz->id,
            'score' => $grade['score'],
            'total_questions' => $grade['total_questions'],
            'time_spent' => $data['time_spent'],
            'answers_log' => $grade['answers_log'],
        ]);

        $total = max(1, (int) $result->total_questions);
        ClickHouseService::log('complete_quiz', $result->quiz_id, [
            'score' => (int) $result->score,
            'total_questions' => (int) $result->total_questions,
            'percent' => (float) (($result->score / $total) * 100),
            'via' => 'mobile',
        ]);

        return response()->json([
            'ok' => true,
            'result_id' => $result->id,
            'score' => $result->score,
            'total_questions' => $result->total_questions,
        ]);
    }
}
