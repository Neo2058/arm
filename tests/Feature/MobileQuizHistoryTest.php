<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\Quiz;
use App\Models\QuizResult;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MobileQuizHistoryTest extends TestCase
{
    use RefreshDatabase;

    private function createResult(User $user, Quiz $quiz): QuizResult
    {
        return QuizResult::create(['user_id' => $user->id, 'quiz_id' => $quiz->id,
            'score' => 2, 'total_questions' => 3, 'time_spent' => 91, 'answers_log' => [1 => 2]]);
    }

    public function test_history_is_private_paginated_and_does_not_expose_answers(): void
    {
        $this->getJson('/api/mobile/quizzes/history')->assertUnauthorized();
        $user = User::factory()->driver()->create();
        $other = User::factory()->driver()->create();
        $quiz = Quiz::create(['title' => 'Общий тест', 'is_active' => true]);
        $foreign = $this->createResult($other, $quiz);
        for ($i = 0; $i < 21; $i++) {
            $latest = $this->createResult($user, $quiz);
        }
        $this->withToken($user->createToken('mobile')->plainTextToken);
        $response = $this->getJson('/api/mobile/quizzes/history?user_id='.$other->id)
            ->assertOk()->assertJsonCount(20, 'results')->assertJsonPath('has_more', true)
            ->assertJsonPath('results.0.id', $latest->id)
            ->assertJsonPath('results.0.time_spent', 91);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertArrayNotHasKey('answers_log', $response->json('results.0'));
        $this->assertArrayNotHasKey('user_id', $response->json('results.0'));
        $this->getJson('/api/mobile/quizzes/history?page=2')->assertOk()
            ->assertJsonCount(1, 'results')->assertJsonPath('has_more', false)
            ->assertJsonMissing(['id' => $foreign->id]);
        $this->getJson('/api/mobile/quizzes/history?page=-1')->assertUnprocessable();
    }

    public function test_current_quiz_access_is_respected_including_super_admin(): void
    {
        $user = User::factory()->driver()->create();
        $document = Document::create(['title' => 'Ограниченный', 'file_path' => 'manual.pdf', 'allowed_roles' => ['instructor']]);
        $restricted = Quiz::create(['title' => 'Закрытый', 'is_active' => true, 'document_id' => $document->id]);
        $inactive = Quiz::create(['title' => 'Выключен', 'is_active' => false]);
        $this->createResult($user, $restricted);
        $this->createResult($user, $inactive);
        $this->withToken($user->createToken('mobile')->plainTextToken)
            ->getJson('/api/mobile/quizzes/history')->assertOk()->assertJsonCount(0, 'results');
        $user->update(['role' => 'super_admin']);
        app('auth')->forgetGuards();
        $this->getJson('/api/mobile/quizzes/history')->assertOk()
            ->assertJsonCount(1, 'results')->assertJsonPath('results.0.title', 'Закрытый');
    }
}
