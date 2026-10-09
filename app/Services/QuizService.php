<?php

namespace App\Services;

use App\Models\Quiz;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class QuizService
{
    public function __construct(private FileProxyService $files) {}

    public function authorize(Quiz $quiz, User $user): void
    {
        abort_unless($quiz->is_active, 404);
        $quiz->loadMissing('document');
        abort_if($quiz->document_id && ! $quiz->document, 404);
        abort_if($quiz->document && ! $user->canAccessByRoles($quiz->document->allowed_roles), 403);
    }

    public function playerData(Quiz $quiz, User $user): array
    {
        $this->authorize($quiz, $user);
        $quiz->loadMissing('questions.answers', 'questions.references.document');
        $this->requireQuestions($quiz);

        return [
            'id' => $quiz->id,
            'title' => $quiz->title,
            'time_limit' => $quiz->time_limit,
            'questions' => $quiz->questions->map(fn ($question) => [
                'id' => $question->id,
                'question_text' => $question->question_text,
                // Explicit allowlist: correct answers remain on the server.
                'answers' => $question->answers->map(fn ($answer) => [
                    'id' => $answer->id,
                    'answer_text' => $answer->answer_text,
                ])->values()->all(),
                'references' => $question->references
                    ->filter(fn ($reference) => $reference->document && $user->canAccessByRoles($reference->document->allowed_roles))
                    ->map(fn ($reference) => [
                        'anchor_text' => $reference->anchor_text,
                        'page' => $reference->page_number,
                        'url' => $this->files->temporarySignedRoute('documents.file', 60, $reference->document_id),
                    ])->values()->all(),
            ])->values()->all(),
        ];
    }

    public function grade(Quiz $quiz, User $user, array $answers): array
    {
        $this->authorize($quiz, $user);
        $quiz->loadMissing('questions.answers');
        $this->requireQuestions($quiz);
        $questions = $quiz->questions->keyBy('id');
        $score = 0;
        $normalized = [];

        foreach ($answers as $questionId => $answerId) {
            $question = $questions->get($questionId);
            $answer = $question?->answers->firstWhere('id', (int) $answerId);

            if (! $question || (string) $question->id !== (string) $questionId || ! $answer) {
                throw ValidationException::withMessages([
                    'answers_log' => 'Ответ не принадлежит вопросу этого теста.',
                ]);
            }

            $normalized[$question->id] = $answer->id;
            if ($answer->is_correct) {
                $score++;
            }
        }

        return ['score' => $score, 'total_questions' => $questions->count(), 'answers_log' => $normalized];
    }

    private function requireQuestions(Quiz $quiz): void
    {
        if ($quiz->questions->isEmpty()) {
            throw ValidationException::withMessages(['quiz_id' => 'В тесте нет вопросов.']);
        }
    }
}
