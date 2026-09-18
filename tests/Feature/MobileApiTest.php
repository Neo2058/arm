<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\TrainingMaterial;
use App\Models\TrainingTopic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
    }
}
