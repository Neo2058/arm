<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckDeviceBinding;
use App\Http\Middleware\CheckDynamicBarrier;
use App\Models\Document;
use App\Models\Quiz;
use App\Models\QuizResult;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class QuizSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->withoutMiddleware([CheckDeviceBinding::class, CheckDynamicBarrier::class]);
        $this->actingAs(User::factory()->driver()->create());
    }

    private function quiz(array $attributes = []): Quiz
    {
        $quiz = Quiz::create(array_merge(['title' => 'Тест', 'time_limit' => 30, 'is_active' => true], $attributes));
        $question = $quiz->questions()->create(['question_text' => 'Вопрос']);
        $question->answers()->createMany([
            ['answer_text' => 'Верный', 'is_correct' => true],
            ['answer_text' => 'Неверный', 'is_correct' => false],
        ]);

        return $quiz->load('questions.answers');
    }

    private function restrictedDocument(): Document
    {
        return Document::create([
            'title' => 'Закрытый документ', 'file_path' => 'private.pdf',
            'category' => 'manual', 'allowed_roles' => ['admin'],
        ]);
    }

    private function submit(Quiz $quiz, array $answers, array $extra = [])
    {
        return $this->postJson('/api/quiz-results', array_merge([
            'quiz_id' => $quiz->id, 'time_spent' => 10, 'answers_log' => $answers,
        ], $extra));
    }

    public function test_both_players_receive_no_correct_answer_flags(): void
    {
        $quiz = $this->quiz();

        foreach (["/quiz/{$quiz->id}", '/general-quiz'] as $uri) {
            $response = $this->get($uri)->assertOk();
            $payload = $response->viewData('quiz');
            $this->assertSame($quiz->id, $payload['id']);
            $this->assertCount(2, $payload['questions'][0]['answers']);
            $this->assertArrayNotHasKey('is_correct', $payload['questions'][0]['answers'][0]);
            $this->assertStringNotContainsString('is_correct', $response->getContent());
        }
    }

    public function test_restricted_quiz_cannot_be_opened_or_submitted(): void
    {
        $quiz = $this->quiz(['document_id' => $this->restrictedDocument()->id]);

        $this->get("/quiz/{$quiz->id}")->assertForbidden();
        $this->submit($quiz, [])->assertForbidden();
        $this->assertDatabaseCount('quiz_results', 0);
    }

    public function test_inactive_quiz_cannot_be_opened_or_submitted_even_by_admin(): void
    {
        $quiz = $this->quiz(['is_active' => false]);
        $this->actingAs(User::factory()->admin()->create());

        $this->get("/quiz/{$quiz->id}")->assertNotFound();
        $this->submit($quiz, [])->assertNotFound();
        $this->assertDatabaseCount('quiz_results', 0);
    }

    public function test_legacy_client_cannot_forge_score_or_total(): void
    {
        $quiz = $this->quiz();
        $question = $quiz->questions->first();
        $wrong = $question->answers->last();

        $this->submit($quiz, [$question->id => $wrong->id], ['score' => 999, 'total_questions' => 0])->assertOk();
        $result = QuizResult::sole();
        $this->assertSame(0, $result->score);
        $this->assertSame(1, $result->total_questions);
        $this->assertEquals([$question->id => $wrong->id], $result->answers_log);
    }

    public function test_server_counts_correct_and_unanswered_questions(): void
    {
        $quiz = $this->quiz();
        $question = $quiz->questions->first();
        $quiz->questions()->create(['question_text' => 'Без ответа']);

        $this->submit($quiz, [$question->id => $question->answers->first()->id])->assertOk();
        $this->assertDatabaseHas('quiz_results', ['quiz_id' => $quiz->id, 'score' => 1, 'total_questions' => 2]);
    }

    public function test_foreign_question_is_rejected(): void
    {
        $quiz = $this->quiz();
        $other = $this->quiz()->questions->first();

        $this->submit($quiz, [$other->id => $other->answers->first()->id])
            ->assertUnprocessable()->assertJsonValidationErrors('answers_log');
        $this->assertDatabaseCount('quiz_results', 0);
    }

    public function test_permission_revocation_after_opening_also_blocks_submission(): void
    {
        $document = Document::create(['title' => 'Инструкция', 'file_path' => 'manual.pdf', 'category' => 'manual']);
        $quiz = $this->quiz(['document_id' => $document->id]);
        $this->get("/quiz/{$quiz->id}")->assertOk();
        $document->update(['allowed_roles' => ['admin']]);

        $this->submit($quiz, [])->assertForbidden();
        $this->assertDatabaseCount('quiz_results', 0);
    }

    public function test_admin_can_take_active_restricted_quiz(): void
    {
        $quiz = $this->quiz(['document_id' => $this->restrictedDocument()->id]);
        $this->actingAs(User::factory()->admin()->create());

        $this->get("/quiz/{$quiz->id}")->assertOk();
        $this->submit($quiz, [])->assertOk();
    }

    public function test_missing_answer_log_cannot_submit_a_legacy_score_only(): void
    {
        $quiz = $this->quiz();
        $this->postJson('/api/quiz-results', [
            'quiz_id' => $quiz->id, 'score' => 100, 'total_questions' => 100, 'time_spent' => 10,
        ])->assertUnprocessable()->assertJsonValidationErrors('answers_log');
        $this->assertDatabaseCount('quiz_results', 0);
    }

    public function test_answer_from_another_question_is_rejected(): void
    {
        $quiz = $this->quiz();
        $other = $this->quiz()->questions->first();

        $this->submit($quiz, [$quiz->questions->first()->id => $other->answers->first()->id])
            ->assertUnprocessable()->assertJsonValidationErrors('answers_log');
        $this->assertDatabaseCount('quiz_results', 0);
    }

    public function test_empty_quiz_cannot_create_result(): void
    {
        $quiz = Quiz::create(['title' => 'Пустой', 'is_active' => true]);

        $this->submit($quiz, [])->assertUnprocessable()->assertJsonValidationErrors('quiz_id');
        $this->assertDatabaseCount('quiz_results', 0);
    }

    public function test_expired_timer_can_submit_no_answers_for_nonempty_quiz(): void
    {
        $quiz = $this->quiz();
        $this->submit($quiz, [])->assertOk();
        $this->assertDatabaseHas('quiz_results', ['quiz_id' => $quiz->id, 'score' => 0, 'total_questions' => 1]);
    }

    public function test_malformed_answers_and_negative_time_are_rejected(): void
    {
        $quiz = $this->quiz();
        $this->submit($quiz, [$quiz->questions->first()->id => ['nested']], ['time_spent' => -1])
            ->assertUnprocessable()->assertJsonValidationErrors(['answers_log.'.$quiz->questions->first()->id, 'time_spent']);
        $this->assertDatabaseCount('quiz_results', 0);
    }

    public function test_general_quiz_preserves_allowed_references_without_exposing_restricted_ones(): void
    {
        $quiz = $this->quiz();
        $allowed = Document::create(['title' => 'Инструкция', 'file_path' => 'manual.pdf', 'category' => 'manual']);
        $question = $quiz->questions->first();
        $question->references()->createMany([
            ['document_id' => $allowed->id, 'page_number' => 5, 'anchor_text' => 'п. 5.1'],
            ['document_id' => $this->restrictedDocument()->id, 'page_number' => 9, 'anchor_text' => 'Секрет'],
        ]);

        $payload = $this->get('/general-quiz')->assertOk()->viewData('quiz');
        $references = $payload['questions'][0]['references'];
        $this->assertCount(1, $references);
        $this->assertSame('п. 5.1', $references[0]['anchor_text']);
        $this->assertSame(5, $references[0]['page']);
        $this->assertStringContainsString("/documents/{$allowed->id}/file", $references[0]['url']);
        $this->assertStringContainsString('signature=', $references[0]['url']);
    }
}
