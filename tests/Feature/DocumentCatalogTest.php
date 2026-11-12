<?php

namespace Tests\Feature;

use App\Filament\Resources\DocumentFolderResource\Pages\CreateDocumentFolder;
use App\Filament\Resources\DocumentResource;
use App\Filament\Resources\DocumentResource\Pages\CreateDocument;
use App\Http\Controllers\DocumentController;
use App\Models\Document;
use App\Models\DocumentFolder;
use App\Models\InstructionCategory;
use App\Models\User;
use App\Models\UserInstructionSignature;
use App\Services\ClickHouseService;
use App\Services\DocumentPublishing;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class DocumentCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('s3');
        ClickHouseService::fake();
    }

    protected function tearDown(): void
    {
        ClickHouseService::clearFake();
        parent::tearDown();
    }

    public function test_filament_rejects_duplicate_before_storing_a_second_object(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->admin()->create());
        $content = "%PDF-1.4\nIdentical original";
        Storage::disk('s3')->put('uploads/teaching/original.pdf', $content);
        Document::create(['title' => 'Оригинал', 'file_path' => 'uploads/teaching/original.pdf']);
        $upload = UploadedFile::fake()->createWithContent('original.pdf', $content);
        Livewire::test(CreateDocument::class)
            ->fillForm(['title' => 'Копия', 'category' => 'manual', 'file_path' => $upload])
            ->call('create')->assertHasFormErrors()->assertSee('Такой файл уже опубликован');
        $this->assertDatabaseCount('documents', 1);
        $this->assertCount(1, Storage::disk('s3')->allFiles('uploads/teaching'));
    }

    public function test_unique_upload_with_same_name_does_not_overwrite_original(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->admin()->create());
        Storage::disk('s3')->put('uploads/teaching/original.pdf', "%PDF-1.4\nOld original");
        Document::create(['title' => 'Старый', 'file_path' => 'uploads/teaching/original.pdf']);
        $upload = UploadedFile::fake()->createWithContent('original.pdf', "%PDF-1.4\nNew original");
        Livewire::test(CreateDocument::class)
            ->fillForm(['title' => 'Новый', 'category' => 'manual', 'file_path' => $upload])
            ->call('create')->assertHasNoFormErrors();
        $doc = Document::where('title', 'Новый')->firstOrFail();
        $this->assertNotSame('uploads/teaching/original.pdf', $doc->file_path);
        $this->assertSame("%PDF-1.4\nOld original", Storage::disk('s3')->get('uploads/teaching/original.pdf'));
        $this->assertSame(hash('sha256', "%PDF-1.4\nNew original"), $doc->file_sha256);
    }

    public function test_indexing_is_explicit_and_preserves_legacy_duplicates(): void
    {
        Storage::disk('s3')->put('a.pdf', 'same');
        Storage::disk('s3')->put('b.pdf', 'same');
        Document::create(['title' => 'А', 'file_path' => 'a.pdf']);
        Document::create(['title' => 'Б', 'file_path' => 'b.pdf']);
        $this->artisan('documents:index-hashes')->assertSuccessful();
        $this->assertSame(2, Document::whereNull('file_sha256')->count());
        $this->artisan('documents:index-hashes --apply')->assertSuccessful();
        $this->assertSame(0, Document::whereNull('file_sha256')->count());
        $this->assertDatabaseCount('documents', 2);
        $this->assertSame(['a.pdf', 'b.pdf'], Storage::disk('s3')->allFiles());
    }

    public function test_folder_admin_form_creates_nested_folder_and_blocks_driver(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->admin()->create());
        $folder = DocumentFolder::create(['name' => 'Год', 'category' => 'manual']);
        Livewire::test(CreateDocumentFolder::class)
            ->fillForm(['name' => 'Электрика', 'category' => 'manual', 'parent_id' => $folder->id])
            ->call('create')->assertHasNoFormErrors();
        $this->assertDatabaseHas('document_folders', ['name' => 'Электрика', 'parent_id' => $folder->id]);
        $this->actingAs(User::factory()->driver()->create());
        Livewire::test(CreateDocumentFolder::class)->assertForbidden();
    }

    public function test_audit_contains_only_identifiers_and_field_names(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $folder = DocumentFolder::create(['name' => 'Секретное название', 'category' => 'manual']);
        $folder->update(['name' => 'Другое название']);
        Document::create(['title' => 'Секретный документ', 'file_path' => 'private/path.pdf']);
        $events = ClickHouseService::recorded();
        $this->assertCount(3, $events);
        $serialized = json_encode($events, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('Секрет', $serialized);
        $this->assertStringNotContainsString('private/path.pdf', $serialized);
    }

    public function test_folders_are_additive_and_do_not_reveal_restricted_documents(): void
    {
        $root = DocumentFolder::create(['name' => '2026', 'category' => 'manual']);
        $child = DocumentFolder::create(['name' => 'Электрика', 'category' => 'manual', 'parent_id' => $root->id]);
        $user = User::factory()->driver()->create();
        Document::create(['title' => 'Открытый', 'category' => 'manual', 'file_path' => 'a.pdf', 'document_folder_id' => $child->id, 'publication_year' => 2026, 'document_number' => 'И-12']);
        Document::create(['title' => 'Закрытый', 'category' => 'manual', 'file_path' => 'b.pdf', 'document_folder_id' => $child->id, 'allowed_roles' => ['instructor']]);
        $this->withToken($user->createToken('mobile')->plainTextToken)
            ->getJson('/api/mobile/documents')->assertOk()->assertJsonCount(1, 'categories.0.items')
            ->assertJsonPath('categories.0.items.0.folder_path.0.name', '2026')
            ->assertJsonPath('categories.0.items.0.folder_path.1.name', 'Электрика')
            ->assertJsonPath('categories.0.items.0.document_number', 'И-12')->assertJsonMissing(['title' => 'Закрытый']);
    }

    public function test_moving_a_document_keeps_its_id_original_and_signatures(): void
    {
        Storage::disk('s3')->put('a.pdf', '%PDF original');
        $category = InstructionCategory::create(['name' => 'Инструктаж', 'slug' => 'briefing', 'is_active' => true]);
        $user = User::factory()->driver()->create();
        $doc = Document::create(['title' => 'Инструкция', 'file_path' => 'a.pdf', 'category' => 'manual', 'instruction_category_id' => $category->id]);
        $signature = UserInstructionSignature::create(['user_id' => $user->id, 'document_id' => $doc->id, 'instruction_category_id' => $category->id, 'signed_at' => now()]);
        $date = $signature->signed_at->toIso8601String();
        $folder = DocumentFolder::create(['name' => '2026', 'category' => 'manual']);
        $saved = app(DocumentPublishing::class)->save(['document_folder_id' => $folder->id], $doc);
        $this->assertSame($doc->id, $saved->id);
        $this->assertSame($date, $signature->fresh()->signed_at->toIso8601String());
        $this->assertSame('a.pdf', $saved->file_path);
        $this->assertFalse(DocumentResource::canDelete($saved));
        $this->actingAs($user);
        $view = app(DocumentController::class)->index();
        $this->assertSame('2026', $view->getData()['groupedDocuments'][0]['items'][0]['folder_path'][0]['name']);
    }

    public function test_folder_depth_is_bounded(): void
    {
        $parent = null;
        for ($depth = 1; $depth <= 6; $depth++) {
            $parent = DocumentFolder::create(['name' => 'Уровень '.$depth, 'category' => 'manual', 'parent_id' => $parent?->id]);
        }
        $this->assertCount(6, $parent->path());
        $this->expectException(ValidationException::class);
        DocumentFolder::create(['name' => 'Седьмой', 'category' => 'manual', 'parent_id' => $parent->id]);
    }

    public function test_folder_parent_must_belong_to_same_category(): void
    {
        $parent = DocumentFolder::create(['name' => 'Папка', 'category' => 'manual']);
        $this->expectException(ValidationException::class);
        DocumentFolder::create(['name' => 'Ошибка', 'category' => 'order', 'parent_id' => $parent->id]);
    }

    public function test_folder_links_cannot_cycle_or_change_category(): void
    {
        $folder = DocumentFolder::create(['name' => 'Папка', 'category' => 'manual']);
        $this->expectException(ValidationException::class);
        $folder->update(['parent_id' => $folder->id]);
    }

    public function test_document_cannot_be_placed_in_another_category_folder(): void
    {
        $folder = DocumentFolder::create(['name' => 'Папка', 'category' => 'manual']);
        $this->expectException(ValidationException::class);
        Document::create(['title' => 'Ошибка', 'category' => 'order', 'file_path' => 'a.pdf', 'document_folder_id' => $folder->id]);
    }

    public function test_identical_bytes_are_rejected_even_for_an_unindexed_legacy_document(): void
    {
        Storage::disk('s3')->put('old.pdf', '%PDF identical');
        Storage::disk('s3')->put('new.pdf', '%PDF identical');
        Document::create(['title' => 'Старый', 'file_path' => 'old.pdf']);
        try {
            app(DocumentPublishing::class)->save(['title' => 'Другой заголовок', 'file_path' => 'new.pdf']);
            $this->fail('Duplicate accepted');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('file_path', $error->errors());
        }
        $this->assertDatabaseCount('documents', 1);
        $this->assertTrue(Storage::disk('s3')->exists('old.pdf'));
        $this->assertTrue(Storage::disk('s3')->exists('new.pdf'));
    }

    public function test_unreadable_original_blocks_publishing_instead_of_skipping_duplicate_check(): void
    {
        Document::create(['title' => 'Недоступный', 'file_path' => 'missing.pdf']);
        Storage::disk('s3')->put('new.pdf', '%PDF new');
        try {
            app(DocumentPublishing::class)->save(['title' => 'Новый', 'file_path' => 'new.pdf']);
            $this->fail('Publication skipped missing original');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('file_path', $error->errors());
        }
        $this->assertDatabaseCount('documents', 1);
        $this->assertTrue(Storage::disk('s3')->exists('new.pdf'));
    }

    public function test_metadata_edits_preserve_original_but_replacement_is_blocked(): void
    {
        Storage::disk('s3')->put('a.pdf', '%PDF original');
        $publisher = app(DocumentPublishing::class);
        $doc = $publisher->save(['title' => 'Инструкция', 'file_path' => 'a.pdf']);
        $saved = $publisher->save(['publication_year' => 2026], $doc);
        $this->assertSame(2026, $saved->publication_year);
        $this->assertSame(hash('sha256', '%PDF original'), $saved->file_sha256);
        $this->expectException(ValidationException::class);
        $publisher->save(['file_path' => 'new.pdf'], $doc);
    }
}
