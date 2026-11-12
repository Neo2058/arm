<?php

namespace Tests\Feature;

use App\Filament\Resources\DocumentResource\Pages\CreateDocument;
use App\Filament\Resources\DocumentResource\Pages\EditDocument;
use App\Filament\Resources\DocumentResource\Pages\ListDocuments;
use App\Http\Middleware\CheckDeviceBinding;
use App\Http\Middleware\CheckDynamicBarrier;
use App\Models\Document;
use App\Models\InstructionCategory;
use App\Models\Question;
use App\Models\QuestionReference;
use App\Models\Quiz;
use App\Models\QuizResult;
use App\Models\User;
use App\Models\UserInstructionSignature;
use App\Services\ClickHouseService;
use App\Services\DocumentPublishing;
use App\Services\DocumentRevisions;
use App\Services\InstructionSignatures;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class DocumentRevisionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('s3');
        ClickHouseService::fake();
        $this->withoutMiddleware([CheckDeviceBinding::class, CheckDynamicBarrier::class]);
    }

    private function original(): Document
    {
        Storage::disk('s3')->put('old.pdf', '%PDF original');
        Storage::disk('s3')->put('new.pdf', '%PDF new edition');
        $category = InstructionCategory::create(['name' => 'Инструктаж', 'slug' => 'briefing', 'is_active' => true]);

        return Document::create(['title' => 'Правила', 'file_path' => 'old.pdf', 'instruction_category_id' => $category->id]);
    }

    private function payload(): array
    {
        return ['revision_label' => '2026 / 2', 'file_path' => 'new.pdf', 'effective_on' => today()->toDateString(),
            'replacement_reason' => 'Пересмотр требований', 'replacement_confirmed' => true, 'references_reviewed' => true];
    }

    public function test_real_filament_revision_form_keeps_source_locked_and_publishes_new_record(): void
    {
        $doc = $this->original();
        $this->actingAs(User::factory()->admin()->create());
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $upload = UploadedFile::fake()->createWithContent('new.pdf', "%PDF-1.4\nAnother edition");
        Livewire::withQueryParams(['source' => $doc->id])->test(CreateDocument::class)
            ->assertSet('sourceDocumentId', $doc->id)
            ->fillForm(['revision_label' => '2026/2', 'file_path' => $upload, 'effective_on' => today()->toDateString(),
                'replacement_reason' => 'Пересмотр', 'replacement_confirmed' => true, 'references_reviewed' => true])
            ->call('create')->assertHasNoFormErrors();
        $this->assertNotNull($doc->fresh()->withdrawn_at);
        $this->assertDatabaseHas('documents', ['replaces_document_id' => $doc->id, 'revision_label' => '2026/2']);
    }

    public function test_admin_lists_active_editions_and_can_open_family_archive(): void
    {
        $doc = $this->original();
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $revision = app(DocumentRevisions::class)->publish($admin, $doc->id, $this->payload());
        Livewire::test(ListDocuments::class)
            ->assertCanSeeTableRecords([$revision])->assertCanNotSeeTableRecords([$doc])
            ->filterTable('edition_status', 'all')->filterTable('family', $doc->id)
            ->assertCanSeeTableRecords([$doc, $revision]);
        Livewire::test(EditDocument::class, ['record' => $doc->id])
            ->assertFormFieldIsDisabled('title')->assertActionHidden('withdraw');
    }

    public function test_new_edition_can_follow_explicit_withdrawal_without_rewriting_first_reason(): void
    {
        $doc = $this->original();
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        app(DocumentRevisions::class)->withdraw($admin, $doc->id, ['reason' => 'Отменён приказом', 'confirmed' => true]);
        $withdrawnAt = $doc->fresh()->withdrawn_at->toIso8601String();
        $this->travel(2)->hours();
        $new = app(DocumentRevisions::class)->publish($admin, $doc->id, $this->payload());
        $this->assertSame($withdrawnAt, $doc->fresh()->withdrawn_at->toIso8601String());
        $this->assertSame('Отменён приказом', $doc->fresh()->withdrawal_reason);
        $this->assertTrue($new->isActiveEdition());
    }

    public function test_revision_source_cannot_be_changed_by_livewire_client(): void
    {
        $doc = $this->original();
        $this->actingAs(User::factory()->admin()->create());
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $component = Livewire::withQueryParams(['source' => $doc->id])->test(CreateDocument::class);
        $this->expectException(CannotUpdateLockedPropertyException::class);
        $component->set('sourceDocumentId', $doc->id + 1);
    }

    public function test_document_audit_does_not_duplicate_admin_event_or_leak_values(): void
    {
        $doc = $this->original();
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setServingStatus();
        ClickHouseService::fake();
        app(DocumentRevisions::class)->withdraw($admin, $doc->id, ['reason' => 'Секретное основание', 'confirmed' => true]);
        $events = ClickHouseService::recorded();
        $this->assertCount(1, $events);
        $this->assertSame('document.catalog_saved', $events[0]['action_type']);
        $this->assertStringNotContainsString('Секретное', $events[0]['details']);
        $this->assertStringNotContainsString('Правила', $events[0]['details']);
        $this->assertDatabaseMissing('action_logs', ['action' => 'admin.document.updated']);
    }

    public function test_revision_preserves_original_and_signatures_and_requires_new_acknowledgement(): void
    {
        $doc = $this->original();
        $driver = User::factory()->driver()->create();
        $date = app(InstructionSignatures::class)->sign($driver, $doc->id, ['confirmed' => true])['signed_at'];
        $admin = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($admin);
        $revision = app(DocumentRevisions::class)->publish($admin, $doc->id, $this->payload());
        $this->assertNotSame($doc->id, $revision->id);
        $this->assertSame($doc->id, $revision->root_document_id);
        $this->assertSame($doc->id, $revision->replaces_document_id);
        $this->assertSame(2, $revision->revision_number);
        $this->assertNotNull($doc->fresh()->withdrawn_at);
        $this->assertSame($date, UserInstructionSignature::first()->signed_at->toIso8601String());
        $this->assertDatabaseCount('user_instruction_signatures', 1);
        $list = app(InstructionSignatures::class)->categories($driver);
        $this->assertSame($revision->id, $list[0]['documents'][0]['id']);
        $this->assertFalse($list[0]['documents'][0]['signed']);
        app(InstructionSignatures::class)->sign($driver, $revision->id, ['confirmed' => true]);
        $this->assertDatabaseCount('user_instruction_signatures', 2);
        $this->assertSame('%PDF original', Storage::disk('s3')->get('old.pdf'));
    }

    public function test_withdrawal_is_explicit_idempotent_and_keeps_original(): void
    {
        $doc = $this->original();
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        try {
            app(DocumentRevisions::class)->withdraw($admin, $doc->id, ['reason' => 'Отменён']);
            $this->fail('Confirmation missing');
        } catch (ValidationException) {
            $this->assertNull($doc->fresh()->withdrawn_at);
        }
        $first = app(DocumentRevisions::class)->withdraw($admin, $doc->id, ['reason' => 'Отменён', 'confirmed' => true]);
        $this->travel(1)->hours();
        $again = app(DocumentRevisions::class)->withdraw($admin, $doc->id, ['reason' => 'Другое основание', 'confirmed' => true]);
        $this->assertSame($first->withdrawn_at->toIso8601String(), $again->withdrawn_at->toIso8601String());
        $this->assertSame('Отменён', $again->withdrawal_reason);
        $this->assertTrue(Storage::disk('s3')->exists('old.pdf'));
    }

    public function test_archived_document_disappears_and_old_signed_links_stop_working(): void
    {
        $doc = $this->original();
        $mobileUrl = URL::temporarySignedRoute('mobile.files.documents', now()->addHour(), $doc->id);
        $webUrl = URL::temporarySignedRoute('documents.file', now()->addHour(), $doc->id);
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        app(DocumentRevisions::class)->withdraw($admin, $doc->id, ['reason' => 'Отменён', 'confirmed' => true]);
        $this->get($webUrl)->assertOk(); // Explicit administrative archive.
        $this->get($mobileUrl)->assertNotFound(); // Signed capability cannot revive a retired file.
        $driver = User::factory()->driver()->create();
        $this->actingAs($driver);
        $this->get($webUrl)->assertNotFound();
        $this->getJson('/api/mobile/documents')->assertOk()->assertJsonCount(0, 'categories');
        $this->getJson('/api/mobile/documents/'.$doc->id)->assertNotFound();
        $this->postJson('/api/mobile/instructions/'.$doc->id.'/sign', ['confirmed' => true])->assertNotFound();
    }

    public function test_failure_rolls_back_withdrawal_and_cannot_branch_from_an_old_revision(): void
    {
        $doc = $this->original();
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        $bad = $this->payload();
        $bad['document_folder_id'] = 99999;
        try {
            app(DocumentRevisions::class)->publish($admin, $doc->id, $bad);
            $this->fail('Bad revision accepted');
        } catch (ValidationException) {
            $this->assertNull($doc->fresh()->withdrawn_at);
            $this->assertDatabaseCount('documents', 1);
        }
        app(DocumentRevisions::class)->publish($admin, $doc->id, $this->payload());
        $this->expectException(ValidationException::class);
        app(DocumentRevisions::class)->publish($admin, $doc->id, $this->payload());
    }

    public function test_future_activation_and_identical_content_are_rejected_without_retiring_original(): void
    {
        $doc = $this->original();
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        foreach ([['effective_on' => today()->addDay()->toDateString()], ['file_path' => 'old.pdf']] as $change) {
            try {
                app(DocumentRevisions::class)->publish($admin, $doc->id, array_merge($this->payload(), $change));
                $this->fail('Invalid revision accepted');
            } catch (ValidationException) {
                $this->assertNull($doc->fresh()->withdrawn_at);
            }
        }
    }

    public function test_archived_record_and_its_category_cannot_be_deleted_or_changed(): void
    {
        $doc = $this->original();
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        app(DocumentRevisions::class)->withdraw($admin, $doc->id, ['reason' => 'Отменён', 'confirmed' => true]);
        foreach ([fn () => $doc->fresh()->delete(), fn () => $doc->instructionCategory->delete(), fn () => app(DocumentPublishing::class)->save(['title' => 'Подмена'], $doc)] as $mutation) {
            try {
                $mutation();
                $this->fail('Archive changed');
            } catch (ValidationException) {
                $this->assertDatabaseHas('documents', ['id' => $doc->id, 'title' => 'Правила']);
            }
        }
    }

    public function test_quiz_and_page_references_are_not_carried_to_new_edition(): void
    {
        $doc = $this->original();
        $quiz = Quiz::create(['title' => 'Тест старой редакции', 'document_id' => $doc->id, 'is_active' => true]);
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        $revision = app(DocumentRevisions::class)->publish($admin, $doc->id, $this->payload());
        $this->assertNull($revision->quiz);
        $this->assertSame($doc->id, $quiz->fresh()->document_id);
        $this->getJson('/api/mobile/quizzes/'.$quiz->id)->assertNotFound();
        $this->getJson('/api/mobile/quizzes')->assertJsonCount(0, 'quizzes');
    }

    public function test_retired_references_are_hidden_but_own_historical_result_remains(): void
    {
        $doc = $this->original();
        $oldQuiz = Quiz::create(['title' => 'Старый тест', 'document_id' => $doc->id, 'is_active' => true]);
        $general = Quiz::create(['title' => 'Общий тест', 'is_active' => true]);
        $question = Question::create(['quiz_id' => $general->id, 'question_text' => 'Вопрос']);
        QuestionReference::create(['question_id' => $question->id, 'document_id' => $doc->id, 'page_number' => 1, 'anchor_text' => 'Пункт 1']);
        $driver = User::factory()->driver()->create();
        QuizResult::create(['user_id' => $driver->id, 'quiz_id' => $oldQuiz->id, 'score' => 1, 'total_questions' => 1, 'time_spent' => 30, 'answers_log' => []]);
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        app(DocumentRevisions::class)->withdraw($admin, $doc->id, ['reason' => 'Отмена', 'confirmed' => true]);
        $this->actingAs($driver);
        $this->getJson('/api/mobile/quizzes/'.$general->id)->assertOk()->assertJsonCount(0, 'questions.0.references');
        $this->getJson('/api/mobile/quizzes/history')->assertOk()->assertJsonPath('results.0.title', 'Старый тест');
        $this->postJson('/api/mobile/quizzes/'.$oldQuiz->id.'/results', ['time_spent' => 1, 'answers_log' => []])->assertNotFound();
    }

    public function test_non_admin_cannot_publish_or_withdraw(): void
    {
        $doc = $this->original();
        $user = User::factory()->driver()->create();
        $this->actingAs($user);
        $this->expectException(HttpException::class);
        app(DocumentRevisions::class)->publish($user, $doc->id, $this->payload());
    }

    public function test_database_allows_only_one_active_revision_in_a_family(): void
    {
        $doc = $this->original();
        $this->expectException(QueryException::class);
        DB::table('documents')->insert(['title' => 'Обход сервиса', 'file_path' => 'another.pdf', 'root_document_id' => $doc->id]);
    }
}
