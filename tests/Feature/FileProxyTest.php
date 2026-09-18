<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckDeviceBinding;
use App\Http\Middleware\CheckDynamicBarrier;
use App\Models\Document;
use App\Models\TrainingMaterial;
use App\Models\TrainingTopic;
use App\Models\User;
use App\Services\FileProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class FileProxyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('s3');
        Queue::fake();
        Http::fake();

        $this->withoutMiddleware([
            CheckDeviceBinding::class,
            CheckDynamicBarrier::class,
        ]);
    }

    private function putPdf(string $path = 'uploads/teaching/test.pdf'): string
    {
        Storage::disk('s3')->put($path, '%PDF-1.4 test-document');

        return $path;
    }

    private function document(array $overrides = []): Document
    {
        $path = $overrides['file_path'] ?? $this->putPdf();

        return Document::create(array_merge([
            'title' => 'Тестовый документ',
            'file_path' => $path,
            'category' => 'manual',
            'allowed_roles' => null,
        ], $overrides));
    }

    public function test_signed_document_url_is_readable_inline_and_not_a_minio_link(): void
    {
        $driver = User::factory()->driver()->create();
        $document = $this->document();

        $url = URL::temporarySignedRoute('documents.file', now()->addMinutes(30), $document->id);

        $this->assertStringNotContainsString('9000', $url);
        $this->assertStringNotContainsString('minio', strtolower($url));
        $this->assertStringContainsString('/documents/'.$document->id.'/file', $url);

        $response = $this->actingAs($driver)->get($url);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $response->assertHeader('Content-Disposition', 'inline; filename="test.pdf"');
        $response->assertHeader('Accept-Ranges', 'bytes');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('%PDF-1.4 test-document', $response->streamedContent());
    }

    public function test_unsigned_document_url_is_rejected(): void
    {
        $driver = User::factory()->driver()->create();
        $document = $this->document();

        $this->actingAs($driver)
            ->get('/documents/'.$document->id.'/file')
            ->assertForbidden();
    }

    public function test_missing_file_returns_404(): void
    {
        $driver = User::factory()->driver()->create();
        $document = $this->document(['file_path' => 'uploads/teaching/missing.pdf']);

        $url = URL::temporarySignedRoute('documents.file', now()->addMinutes(30), $document->id);

        $this->actingAs($driver)->get($url)->assertNotFound();
    }

    public function test_restricted_document_is_forbidden_for_driver(): void
    {
        $driver = User::factory()->driver()->create();
        $document = $this->document(['allowed_roles' => ['admin']]);

        $url = URL::temporarySignedRoute('documents.file', now()->addMinutes(30), $document->id);

        $this->actingAs($driver)->get($url)->assertForbidden();
    }

    public function test_driver_cannot_download_document(): void
    {
        $driver = User::factory()->driver()->create();
        $document = $this->document();

        $this->actingAs($driver)
            ->get('/documents/'.$document->id.'/download')
            ->assertForbidden();
    }

    public function test_admin_download_is_attachment(): void
    {
        $admin = User::factory()->admin()->create();
        $document = $this->document();

        $response = $this->actingAs($admin)->get('/documents/'.$document->id.'/download');

        $response->assertOk();
        $response->assertHeader('Content-Disposition', 'attachment; filename="test.pdf"');
    }

    public function test_admin_document_preview_proxy_is_admin_only(): void
    {
        $path = $this->putPdf();
        $url = URL::temporarySignedRoute('admin.documents.serve', now()->addMinutes(15), ['path' => $path]);

        $this->actingAs(User::factory()->driver()->create())
            ->get($url)
            ->assertForbidden();

        $response = $this->actingAs(User::factory()->admin()->create())->get($url);

        $response->assertOk();
        $response->assertHeader('Content-Disposition', 'inline; filename="test.pdf"');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_training_video_streams_inline_with_byte_ranges(): void
    {
        $driver = User::factory()->driver()->create();
        $path = 'training-materials/clip.mp4';
        Storage::disk('s3')->put($path, 'fake-mp4-bytes');

        $topic = TrainingTopic::create([
            'title' => 'Тема',
            'slug' => 'tema',
            'is_active' => true,
        ]);

        $material = TrainingMaterial::create([
            'training_topic_id' => $topic->id,
            'title' => 'Клип',
            'type' => 'video',
            'file_path' => $path,
            'file_name' => 'clip.mp4',
            'mime_type' => 'video/mp4',
            'is_active' => true,
        ]);

        $url = URL::temporarySignedRoute('training.video.stream', now()->addMinutes(15), $material);

        $response = $this->actingAs($driver)->get($url);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'video/mp4');
        $response->assertHeader('Content-Disposition', 'inline; filename="clip.mp4"');
        $response->assertHeader('Accept-Ranges', 'bytes');
        $this->assertStringContainsString('fake-mp4-bytes', $response->streamedContent());
    }

    public function test_admin_training_preview_proxy_is_admin_only(): void
    {
        $path = 'training-materials/clip.mp4';
        Storage::disk('s3')->put($path, 'fake-mp4-bytes');
        $url = URL::temporarySignedRoute('admin.training-materials.serve', now()->addMinutes(15), ['path' => $path]);

        $this->actingAs(User::factory()->driver()->create())
            ->get($url)
            ->assertForbidden();

        $response = $this->actingAs(User::factory()->admin()->create())->get($url);

        $response->assertOk();
        $response->assertHeader('Accept-Ranges', 'bytes');
        $response->assertHeader('Content-Disposition', 'inline; filename="clip.mp4"');
    }

    public function test_temporary_signed_route_helper_keeps_ttl_and_route_name(): void
    {
        $document = $this->document();
        $url = app(FileProxyService::class)
            ->temporarySignedRoute('documents.file', 30, $document->id);

        $this->assertStringContainsString('/documents/'.$document->id.'/file', $url);
        $this->assertStringContainsString('signature=', $url);
        $this->assertStringContainsString('expires=', $url);
    }
}
