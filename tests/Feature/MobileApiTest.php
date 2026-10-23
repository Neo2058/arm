<?php

namespace Tests\Feature;

use App\Models\ActionLog;
use App\Models\AdminNotification;
use App\Models\ArmPersonnel;
use App\Models\Document;
use App\Models\Naryad;
use App\Models\NaryadPersonFile;
use App\Models\Quiz;
use App\Models\TrainingMaterial;
use App\Models\TrainingTopic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class MobileApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::fake();
        Storage::fake('s3');
    }

    public function test_driver_can_login_and_fetch_profile(): void
    {
        $user = User::factory()->driver()->create();

        $login = $this->postJson('/api/mobile/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $login->assertOk()->assertJsonPath('user.role', 'driver');
        $token = $login->json('token');
        $this->assertNotEmpty($token);

        $this->withToken($token)
            ->getJson('/api/mobile/me')
            ->assertOk()
            ->assertJsonPath('user.email', $user->email);
    }

    public function test_inactive_user_cannot_login(): void
    {
        $user = User::factory()->driver()->create(['is_active' => false]);

        $this->postJson('/api/mobile/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertForbidden();
    }

    public function test_documents_are_filtered_by_role(): void
    {
        $driver = User::factory()->driver()->create();
        $open = Document::create([
            'title' => 'Общий',
            'file_path' => 'uploads/teaching/open.pdf',
            'category' => 'manual',
            'allowed_roles' => null,
        ]);
        Document::create([
            'title' => 'Только админ',
            'file_path' => 'uploads/teaching/admin.pdf',
            'category' => 'order',
            'allowed_roles' => ['admin'],
        ]);

        $token = $driver->createToken('mobile')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/mobile/documents')
            ->assertOk()
            ->assertJsonFragment(['title' => 'Общий'])
            ->assertJsonMissing(['title' => 'Только админ']);

        $this->withToken($token)
            ->getJson('/api/mobile/documents/'.$open->id)
            ->assertOk()
            ->assertJsonPath('id', $open->id)
            ->assertJsonStructure(['url']);

        $this->assertDatabaseHas('action_logs', [
            'user_id' => $driver->id,
            'action' => 'mobile.view_document',
        ]);
    }

    public function test_driver_cannot_open_restricted_document_url(): void
    {
        $driver = User::factory()->driver()->create();
        $doc = Document::create([
            'title' => 'Секретно',
            'file_path' => 'uploads/teaching/secret.pdf',
            'allowed_roles' => ['admin'],
        ]);

        $this->withToken($driver->createToken('mobile')->plainTextToken)
            ->getJson('/api/mobile/documents/'.$doc->id)
            ->assertForbidden();

        $this->assertDatabaseHas('action_logs', [
            'user_id' => $driver->id,
            'action' => 'violation_document_access',
            'is_violation' => true,
        ]);
    }

    public function test_driver_can_report_document_screenshot_violation(): void
    {
        $driver = User::factory()->driver()->create(['name' => 'Иванов']);
        $document = Document::create([
            'title' => 'Инструкция',
            'file_path' => 'uploads/teaching/open.pdf',
            'allowed_roles' => null,
        ]);

        $this->withToken($driver->createToken('mobile')->plainTextToken)
            ->postJson('/api/mobile/documents/'.$document->id.'/violation', [
                'kind' => 'screenshot',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseHas('action_logs', [
            'user_id' => $driver->id,
            'action' => 'violation_document_screenshot',
            'is_violation' => true,
        ]);
        $this->assertDatabaseHas('admin_notifications', [
            'type' => 'violation',
            'title' => 'Нарушение регламента: попытка снимка экрана',
        ]);
        $this->assertTrue(
            AdminNotification::query()->where('type', 'violation')->where('message', 'like', '%Иванов%')->exists()
        );
    }

    public function test_mobile_download_and_share_attempts_reuse_download_violation(): void
    {
        $driver = User::factory()->driver()->create();
        $document = Document::create([
            'title' => 'Приказ',
            'file_path' => 'uploads/teaching/order.pdf',
            'allowed_roles' => null,
        ]);
        $token = $driver->createToken('mobile')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/mobile/documents/'.$document->id.'/violation', ['kind' => 'download'])
            ->assertOk();
        $this->withToken($token)
            ->postJson('/api/mobile/documents/'.$document->id.'/violation', ['kind' => 'share'])
            ->assertOk();
        $this->withToken($token)
            ->postJson('/api/mobile/documents/'.$document->id.'/violation', ['kind' => 'recording'])
            ->assertOk();

        $this->assertDatabaseHas('action_logs', [
            'user_id' => $driver->id,
            'action' => 'violation_document_download',
            'is_violation' => true,
        ]);
        $this->assertSame(2, ActionLog::query()->where('user_id', $driver->id)->where('action', 'violation_document_download')->count());
        $this->assertDatabaseHas('action_logs', [
            'user_id' => $driver->id,
            'action' => 'violation_document_screenshot',
            'is_violation' => true,
        ]);
    }

    public function test_mobile_violation_rejects_unknown_kind_and_guests(): void
    {
        $driver = User::factory()->driver()->create();
        $document = Document::create([
            'title' => 'Общий',
            'file_path' => 'uploads/teaching/open.pdf',
            'allowed_roles' => null,
        ]);

        $this->postJson('/api/mobile/documents/'.$document->id.'/violation', [
            'kind' => 'screenshot',
        ])->assertUnauthorized();

        $this->withToken($driver->createToken('mobile')->plainTextToken)
            ->postJson('/api/mobile/documents/'.$document->id.'/violation', [
                'kind' => 'clipboard',
            ])
            ->assertUnprocessable();

        $this->assertDatabaseMissing('action_logs', ['action' => 'violation_document_download']);
        $this->assertDatabaseMissing('action_logs', ['action' => 'violation_document_screenshot']);
    }

    public function test_mobile_violation_on_restricted_document_is_access_violation(): void
    {
        $driver = User::factory()->driver()->create();
        $document = Document::create([
            'title' => 'Секретно',
            'file_path' => 'uploads/teaching/secret.pdf',
            'allowed_roles' => ['admin'],
        ]);

        $this->withToken($driver->createToken('mobile')->plainTextToken)
            ->postJson('/api/mobile/documents/'.$document->id.'/violation', [
                'kind' => 'screenshot',
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('action_logs', [
            'user_id' => $driver->id,
            'action' => 'violation_document_access',
            'is_violation' => true,
        ]);
        $this->assertDatabaseMissing('action_logs', [
            'action' => 'violation_document_screenshot',
        ]);
        $this->assertDatabaseCount('admin_notifications', 0);
    }

    public function test_signed_document_file_is_inline_pdf(): void
    {
        Storage::disk('s3')->put('uploads/teaching/test.pdf', '%PDF-1.4 mobile');
        $document = Document::create([
            'title' => 'PDF',
            'file_path' => 'uploads/teaching/test.pdf',
            'allowed_roles' => null,
        ]);

        $url = URL::temporarySignedRoute('mobile.files.documents', now()->addMinutes(30), $document->id);

        $response = $this->get($url);
        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('%PDF-1.4 mobile', $response->streamedContent());

        $this->get('/api/mobile/files/documents/'.$document->id)->assertForbidden();
        $this->assertDatabaseMissing('action_logs', ['action' => 'mobile.view_document']);
    }

    public function test_training_topics_materials_and_comments(): void
    {
        $user = User::factory()->driver()->create();
        $topic = TrainingTopic::create([
            'title' => 'Тема',
            'slug' => 'tema',
            'is_active' => true,
        ]);
        Storage::disk('s3')->put('training-materials/clip.mp4', 'fake-mp4');
        $material = TrainingMaterial::create([
            'training_topic_id' => $topic->id,
            'title' => 'Клип',
            'type' => 'video',
            'file_path' => 'training-materials/clip.mp4',
            'file_name' => 'clip.mp4',
            'mime_type' => 'video/mp4',
            'is_active' => true,
        ]);

        $token = $user->createToken('mobile')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/mobile/training/topics')
            ->assertOk()
            ->assertJsonFragment(['slug' => 'tema']);

        $this->withToken($token)
            ->getJson('/api/mobile/training/topics/tema')
            ->assertOk()
            ->assertJsonFragment(['title' => 'Клип']);

        $this->withToken($token)
            ->getJson('/api/mobile/training/materials/'.$material->id)
            ->assertOk()
            ->assertJsonStructure(['url', 'comments']);

        $this->assertDatabaseHas('action_logs', [
            'user_id' => $user->id,
            'action' => 'mobile.training_material_viewed',
        ]);

        $this->withToken($token)
            ->postJson('/api/mobile/training/materials/'.$material->id.'/comments', [
                'comment' => 'Понятно, спасибо',
            ])
            ->assertCreated()
            ->assertJsonPath('body', 'Понятно, спасибо');

        $this->assertDatabaseHas('training_material_comments', [
            'training_material_id' => $material->id,
            'user_id' => $user->id,
            'comment' => 'Понятно, спасибо',
        ]);
        $this->assertDatabaseHas('action_logs', [
            'user_id' => $user->id,
            'action' => 'mobile.training_comment_added',
        ]);
    }

    public function test_driver_can_open_and_submit_active_quiz(): void
    {
        $driver = User::factory()->driver()->create();
        $quiz = Quiz::create(['title' => 'Аттестация', 'time_limit' => 15, 'is_active' => true]);
        $question = $quiz->questions()->create(['question_text' => 'Сколько?']);
        $right = $question->answers()->create(['answer_text' => 'Два', 'is_correct' => true]);
        $question->answers()->create(['answer_text' => 'Пять', 'is_correct' => false]);

        $token = $driver->createToken('mobile')->plainTextToken;

        $list = $this->withToken($token)->getJson('/api/mobile/quizzes')->assertOk();
        $list->assertJsonFragment(['title' => 'Аттестация']);

        $show = $this->withToken($token)->getJson('/api/mobile/quizzes/'.$quiz->id)->assertOk();
        $this->assertSame($quiz->id, $show->json('id'));
        $this->assertArrayNotHasKey('is_correct', $show->json('questions.0.answers.0'));
        $this->assertDatabaseHas('action_logs', [
            'user_id' => $driver->id,
            'action' => 'mobile.quiz_opened',
        ]);

        $this->withToken($token)
            ->postJson('/api/mobile/quizzes/'.$quiz->id.'/results', [
                'time_spent' => 12,
                'answers_log' => [$question->id => $right->id],
            ])
            ->assertOk()
            ->assertJsonPath('score', 1)
            ->assertJsonPath('total_questions', 1);

        $this->assertDatabaseHas('quiz_results', [
            'user_id' => $driver->id,
            'quiz_id' => $quiz->id,
            'score' => 1,
        ]);
    }

    public function test_driver_cannot_open_restricted_or_inactive_quiz(): void
    {
        $driver = User::factory()->driver()->create();
        $token = $driver->createToken('mobile')->plainTextToken;
        $secret = Document::create([
            'title' => 'Секрет',
            'file_path' => 'uploads/teaching/secret.pdf',
            'allowed_roles' => ['admin'],
        ]);
        $restricted = Quiz::create([
            'title' => 'Закрытый',
            'time_limit' => 10,
            'is_active' => true,
            'document_id' => $secret->id,
        ]);
        $inactive = Quiz::create(['title' => 'Выключен', 'time_limit' => 10, 'is_active' => false]);
        $q = $restricted->questions()->create(['question_text' => 'Q']);
        $q->answers()->create(['answer_text' => 'A', 'is_correct' => true]);

        $this->withToken($token)->getJson('/api/mobile/quizzes')->assertOk()->assertJsonMissing(['title' => 'Закрытый']);
        $this->withToken($token)->getJson('/api/mobile/quizzes/'.$restricted->id)->assertForbidden();
        $this->withToken($token)->getJson('/api/mobile/quizzes/'.$inactive->id)->assertNotFound();
        $this->withToken($token)
            ->postJson('/api/mobile/quizzes/'.$restricted->id.'/results', [
                'time_spent' => 1,
                'answers_log' => [],
            ])
            ->assertForbidden();
        $this->assertDatabaseCount('quiz_results', 0);
    }

    public function test_driver_sees_own_naryad_and_signed_pdf(): void
    {
        $driver = User::factory()->driver()->create();
        Storage::disk('s3')->put('naryads/full.pdf', '%PDF-1.4 naryad');
        Storage::disk('s3')->put('naryads/people/driver.txt', 'БОБРОВ В.А  16.12 ДПЧ');
        $naryad = Naryad::create([
            'title' => 'Наряд 24.10.2026',
            'file_path' => 'naryads/full.pdf',
            'naryad_date' => '2026-10-24',
            'allowed_roles' => null,
        ]);
        NaryadPersonFile::create([
            'naryad_id' => $naryad->id,
            'user_id' => $driver->id,
            'fio' => $driver->name,
            'file_name' => 'driver.txt',
            'file_path' => 'naryads/people/driver.txt',
        ]);
        $adminOnly = Naryad::create([
            'title' => 'Только админ',
            'file_path' => 'naryads/admin.pdf',
            'naryad_date' => '2026-10-01',
            'allowed_roles' => ['admin'],
        ]);

        $token = $driver->createToken('mobile')->plainTextToken;
        $this->withToken($token)
            ->getJson('/api/mobile/naryads')
            ->assertOk()
            ->assertJsonFragment(['title' => 'Наряд 24.10.2026'])
            ->assertJsonMissing(['title' => 'Только админ']);

        $show = $this->withToken($token)->getJson('/api/mobile/naryads/'.$naryad->id)->assertOk();
        $show->assertJsonPath('personal_text', 'БОБРОВ В.А  16.12 ДПЧ');
        $url = $show->json('url');
        $this->assertNotEmpty($url);
        $this->assertStringContainsString('/api/mobile/files/naryads/'.$naryad->id, $url);
        $this->assertStringNotContainsString('minio', strtolower($url));

        $this->get($url)
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->get('/api/mobile/files/naryads/'.$naryad->id)->assertForbidden();
        $this->withToken($token)->getJson('/api/mobile/naryads/'.$adminOnly->id)->assertForbidden();
        $this->assertDatabaseHas('action_logs', [
            'user_id' => $driver->id,
            'action' => 'mobile.view_naryad',
        ]);
    }

    public function test_driver_can_search_personnel_phones(): void
    {
        $driver = User::factory()->driver()->create();
        $colleague = User::factory()->driver()->create();
        $fired = User::factory()->driver()->create();
        ArmPersonnel::create([
            'user_id' => $colleague->id,
            'tab_number' => '0321',
            'full_name' => 'ИВАНОВ И.И.',
            'phone_primary' => '9991234567',
            'phone_secondary' => '9990001111',
        ]);
        ArmPersonnel::create([
            'user_id' => $fired->id,
            'tab_number' => '0322',
            'full_name' => 'ПЕТРОВ П.П.',
            'phone_primary' => '9997778899',
            'fired_on' => now()->subDay()->toDateString(),
        ]);

        $token = $driver->createToken('mobile')->plainTextToken;
        $this->withToken($token)
            ->getJson('/api/mobile/phones')
            ->assertOk()
            ->assertJsonFragment(['name' => 'ИВАНОВ И.И.', 'phone' => '9991234567'])
            ->assertJsonMissing(['name' => 'ПЕТРОВ П.П.']);

        $this->withToken($token)
            ->getJson('/api/mobile/phones?'.http_build_query(['q' => 'Иванов']))
            ->assertOk()
            ->assertJsonPath('phones.0.name', 'ИВАНОВ И.И.');

        $this->withToken($token)
            ->getJson('/api/mobile/phones?'.http_build_query(['q' => '999123']))
            ->assertOk()
            ->assertJsonPath('phones.0.phone', '9991234567');

        $this->withToken($token)
            ->getJson('/api/mobile/phones?'.http_build_query(['q' => 'неттакого']))
            ->assertOk()
            ->assertJsonPath('phones', []);
    }
}
