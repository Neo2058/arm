<?php

namespace Tests\Feature;

use App\Filament\Discipline\Pages\DisciplineMonitor;
use App\Http\Middleware\CheckDeviceBinding;
use App\Http\Middleware\CheckDynamicBarrier;
use App\Models\DisciplineAssignment;
use App\Models\DisciplineFile;
use App\Models\DisciplineReport;
use App\Models\JournalTask;
use App\Models\User;
use App\Services\ClickHouseService;
use App\Services\Discipline\DisciplineService;
use App\Services\Discipline\ReportScanner;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class DisciplineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([CheckDeviceBinding::class, CheckDynamicBarrier::class]);
        ClickHouseService::fake();
        Storage::fake('s3');
        $this->mock(ReportScanner::class)->shouldReceive('scan')->andReturnNull()->byDefault();
    }

    private function manager(string $role = 'tormoznik'): User
    {
        return User::factory()->create(['role' => $role]);
    }

    private function payload(array $extra = []): array
    {
        return array_replace([
            'operation_id' => (string) Str::uuid(), 'title' => 'Исполнительская дисциплина', 'instructions' => 'Подготовить отчёт',
            'due_date' => today()->addDays(3)->toDateString(), 'all_instructors' => true, 'assignee_ids' => [],
            'require_response' => true, 'require_report_file' => false, 'require_explanation_file' => false,
        ], $extra);
    }

    private function assign(User $manager, User $instructor, array $extra = []): DisciplineAssignment
    {
        $result = app(DisciplineService::class)->create($manager, $this->payload(array_replace([
            'all_instructors' => false, 'assignee_ids' => [$instructor->id],
        ], $extra)));

        return DisciplineAssignment::where('card_id', $result['card_id'])->firstOrFail();
    }

    private function submission(int $version = 1): array
    {
        return ['operation_id' => (string) Str::uuid(), 'version' => $version, 'response' => 'Работа выполнена'];
    }

    public function test_all_instructors_have_independent_execution_and_late_accounts_are_not_silently_added(): void
    {
        $manager = $this->manager();
        $one = User::factory()->instructor()->create();
        $two = User::factory()->instructor()->create();
        User::factory()->instructor()->create(['is_active' => false]);
        User::factory()->driver()->create();
        $payload = $this->payload();
        $service = app(DisciplineService::class);
        $created = $service->create($manager, $payload);
        $this->assertSame($created, $service->create($manager, $payload));
        $this->assertDatabaseCount('discipline_cards', 1);
        $this->assertDatabaseCount('discipline_assignments', 2);
        $this->assertDatabaseCount('discipline_notifications', 2);
        User::factory()->instructor()->create();
        $assignment = DisciplineAssignment::where('assignee_id', $one->id)->firstOrFail();
        $service->submit($one, $assignment->id, $this->submission(), []);
        $this->assertDatabaseHas('discipline_assignments', ['assignee_id' => $two->id, 'status' => 'assigned', 'version' => 1]);
        $this->assertDatabaseCount('discipline_assignments', 2);
    }

    public function test_return_resubmit_accept_history_and_idempotency(): void
    {
        $creator = $this->manager();
        $chief = $this->manager('nachalnik');
        $user = User::factory()->instructor()->create();
        $a = $this->assign($creator, $user);
        $submit = $this->submission();
        $uri = '/discipline/'.$a->id;
        $this->actingAs($user)->post($uri.'/submit', $submit)->assertRedirect($uri);
        $this->post($uri.'/submit', $submit)->assertRedirect($uri);
        $this->assertDatabaseCount('discipline_reports', 1);
        $report = DisciplineReport::firstOrFail();
        $review = ['operation_id' => (string) Str::uuid(), 'version' => 2, 'report_id' => $report->id, 'decision' => 'returned', 'comment' => 'Дополнить'];
        $this->actingAs($chief)->post($uri.'/review', $review)->assertRedirect();
        $this->post($uri.'/review', $review)->assertRedirect();
        $this->assertDatabaseHas('discipline_assignments', ['id' => $a->id, 'status' => 'needs_revision', 'version' => 3]);
        $this->actingAs($user)->post($uri.'/submit', $this->submission(3))->assertRedirect();
        $last = DisciplineReport::latest('id')->firstOrFail();
        $review = array_replace($review, ['operation_id' => (string) Str::uuid(), 'version' => 4, 'report_id' => $last->id, 'decision' => 'accepted', 'comment' => 'Принято']);
        $this->actingAs($chief)->post($uri.'/review', $review)->assertRedirect();
        $this->assertDatabaseHas('discipline_assignments', ['id' => $a->id, 'status' => 'accepted', 'version' => 5]);
        $this->assertNotNull($a->fresh()->accepted_at);
        $this->assertDatabaseHas('discipline_reports', ['id' => $report->id, 'decision' => 'returned', 'review_comment' => 'Дополнить']);
        $this->assertDatabaseCount('discipline_reports', 2);
        $this->actingAs($user)->get($uri)->assertOk()->assertSee('Попытка №2')->assertSee('Дополнить');
        $this->post($uri.'/submit', $this->submission(5))->assertConflict();
    }

    public function test_only_author_or_chief_can_review_and_instructors_only_access_their_assignment(): void
    {
        $creator = $this->manager();
        $otherManager = $this->manager();
        $user = User::factory()->instructor()->create();
        $other = User::factory()->instructor()->create();
        $a = $this->assign($creator, $user);
        $uri = '/discipline/'.$a->id;
        $this->actingAs($other)->get($uri)->assertNotFound();
        $this->post($uri.'/submit', $this->submission())->assertNotFound();
        $this->actingAs($otherManager)->get($uri)->assertNotFound();
        $this->actingAs($user)->get('/discipline')->assertOk()->assertSee('Исполнительская дисциплина');
        $this->post($uri.'/review', [])->assertForbidden();
        foreach (['admin', 'super_admin', 'driver', 'dispatcher', 'student', 'operator'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->get($uri)->assertForbidden();
        }
        $chief = $this->manager('nachalnik');
        $this->actingAs($chief)->get($uri)->assertOk();
    }

    public function test_version_conflict_changed_replay_and_return_comment(): void
    {
        $manager = $this->manager();
        $user = User::factory()->instructor()->create();
        $a = $this->assign($manager, $user);
        $uri = '/discipline/'.$a->id;
        $submit = $this->submission();
        $this->actingAs($user)->post($uri.'/submit', array_replace($submit, ['version' => 9]))->assertStatus(412);
        $this->post($uri.'/submit', $submit)->assertRedirect();
        $this->post($uri.'/submit', array_replace($submit, ['response' => 'Изменено']))->assertConflict();
        $this->post($uri.'/submit', $this->submission())->assertStatus(412);
        $review = ['operation_id' => (string) Str::uuid(), 'version' => 2, 'report_id' => DisciplineReport::firstOrFail()->id, 'decision' => 'returned', 'comment' => '  '];
        $this->actingAs($manager)->postJson($uri.'/review', $review)->assertUnprocessable();
        $review['comment'] = 'Доработать';
        $this->post($uri.'/review', $review)->assertRedirect();
        $this->post($uri.'/review', array_replace($review, ['operation_id' => (string) Str::uuid(), 'decision' => 'accepted']))->assertStatus(412);
    }

    public function test_required_files_validated_scanned_private_and_not_duplicated_on_retry(): void
    {
        $manager = $this->manager();
        $user = User::factory()->instructor()->create();
        $other = User::factory()->instructor()->create();
        $a = $this->assign($manager, $user, ['require_report_file' => true, 'require_explanation_file' => true]);
        $uri = '/discipline/'.$a->id;
        $data = $this->submission();
        $this->actingAs($user)->postJson($uri.'/submit', $data)->assertUnprocessable();
        $pdf = fn () => UploadedFile::fake()->createWithContent('отчёт.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF");
        $uploads = ['report_files' => [$pdf()], 'explanation_files' => [$pdf()]];
        $this->post($uri.'/submit', $data + $uploads)->assertRedirect();
        $this->post($uri.'/submit', $data + $uploads)->assertRedirect();
        $this->assertDatabaseCount('discipline_files', 2);
        $this->assertCount(2, Storage::disk('s3')->allFiles('discipline'));
        $file = DisciplineFile::firstOrFail();
        $this->assertSame('private', Storage::disk('s3')->getVisibility($file->path));
        $this->get('/discipline/files/'.$file->id)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->actingAs($other)->get('/discipline/files/'.$file->id)->assertNotFound();
        $this->actingAs($manager)->get('/discipline/files/'.$file->id)->assertOk();
    }

    public function test_scanner_failure_and_wrong_file_type_never_publish_report_or_file(): void
    {
        $a = $this->assign($this->manager(), $user = User::factory()->instructor()->create());
        $uri = '/discipline/'.$a->id.'/submit';
        $tmp = tempnam(sys_get_temp_dir(), 'invalid-pdf');
        file_put_contents($tmp, 'not a PDF');
        try {
            $this->actingAs($user)->postJson($uri, $this->submission() + ['report_files' => [new UploadedFile($tmp, 'report.pdf', 'application/pdf', null, true)]])->assertUnprocessable();
        } finally {
            unlink($tmp);
        }
        $this->mock(ReportScanner::class)->shouldReceive('scan')->andReturnUsing(fn () => abort(503));
        $this->post($uri, $this->submission() + ['report_files' => [UploadedFile::fake()->createWithContent('report.pdf', "%PDF-1.4\n%%EOF")]])->assertStatus(503);
        $this->assertDatabaseCount('discipline_reports', 0);
        $this->assertDatabaseCount('discipline_files', 0);
        $this->assertCount(0, Storage::disk('s3')->allFiles());
        $this->assertSame('assigned', $a->fresh()->status);
    }

    public function test_roles_have_separate_panel_without_general_admin_permissions_and_can_create_from_livewire(): void
    {
        $manager = $this->manager('nachalnik');
        $user = User::factory()->instructor()->create();
        $this->assertTrue($manager->canAccessPanel(Filament::getPanel('discipline')));
        $this->assertFalse($manager->canAccessPanel(Filament::getPanel('admin')));
        $this->assertFalse($manager->isAdmin());
        $this->assertFalse($manager->canBypassAccessBarriers());
        Filament::setCurrentPanel(Filament::getPanel('discipline'));
        $this->actingAs($manager)->get('/discipline-admin/discipline-monitor')->assertOk();
        $this->get('/admin/users')->assertForbidden();
        Livewire::test(DisciplineMonitor::class)
            ->set('data', array_diff_key($this->payload(), ['operation_id' => true]))
            ->call('createCard')->assertHasNoErrors()->assertSee('Исполнительская дисциплина');
        $this->assertDatabaseCount('discipline_cards', 1);
        $a = DisciplineAssignment::firstOrFail();
        app(DisciplineService::class)->submit($user, $a->id, $this->submission(), []);
        Livewire::test(DisciplineMonitor::class)->assertSee($user->name)->assertSee('Ожидает проверки');
        $this->actingAs($user)->get('/discipline-admin/discipline-monitor')->assertForbidden();
    }

    public function test_role_revocation_is_checked_on_livewire_actions_and_inactive_accounts_are_blocked(): void
    {
        $manager = $this->manager();
        User::factory()->instructor()->create();
        Filament::setCurrentPanel(Filament::getPanel('discipline'));
        $this->actingAs($manager);
        $component = Livewire::test(DisciplineMonitor::class)->set('data', array_diff_key($this->payload(), ['operation_id' => true]));
        DB::table('users')->where('id', $manager->id)->update(['role' => 'driver']);
        $component->call('createCard')->assertForbidden();
        $this->assertDatabaseCount('discipline_cards', 0);
        $user = User::factory()->instructor()->create();
        $this->actingAs($user);
        DB::table('users')->where('id', $user->id)->update(['is_active' => false]);
        $this->getJson('/discipline')->assertForbidden();
    }

    public function test_attachment_count_and_combined_size_limits_are_enforced_before_publication(): void
    {
        $a = $this->assign($this->manager(), $user = User::factory()->instructor()->create());
        $uri = '/discipline/'.$a->id.'/submit';
        $file = fn ($kb) => UploadedFile::fake()->createWithContent('report.pdf', "%PDF-1.4\n%%EOF")->size($kb);
        $this->actingAs($user)->postJson($uri, $this->submission() + ['report_files' => [$file(1), $file(1), $file(1)], 'other_files' => [$file(1), $file(1), $file(1)]])->assertUnprocessable();
        $this->postJson($uri, $this->submission() + ['report_files' => [$file(9000), $file(9000), $file(9000)]])->assertUnprocessable();
        $this->postJson($uri, $this->submission() + ['report_files' => [$file(10241)]])->assertUnprocessable();
        $this->assertDatabaseCount('discipline_files', 0);
        $this->assertDatabaseCount('discipline_reports', 0);
    }

    public function test_existing_personal_tasks_and_accounts_survive_module_migration_and_rollback(): void
    {
        $migration = require database_path('migrations/2026_10_23_000001_create_discipline_module.php');
        $migration->down();
        $user = User::factory()->instructor()->create();
        $task = JournalTask::create(['user_id' => $user->id, 'title' => 'Личная задача', 'status' => 'done', 'source' => 'manual']);
        $migration->up();
        $this->assertDatabaseHas('journal_tasks', ['id' => $task->id, 'user_id' => $user->id, 'status' => 'done']);
        $this->assertSame('instructor', $user->fresh()->roleValue());
        $manager = $this->manager('nachalnik');
        try {
            $migration->down();
            $this->fail('Rollback must refuse while discipline roles are assigned.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Reassign', $e->getMessage());
        }
        $manager->update(['role' => 'instructor']);
        $migration->down();
        $this->assertDatabaseHas('journal_tasks', ['id' => $task->id, 'status' => 'done']);
        $migration->up();
    }
}
