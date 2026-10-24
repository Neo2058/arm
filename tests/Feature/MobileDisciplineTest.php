<?php

namespace Tests\Feature;

use App\Models\DisciplineAssignment;
use App\Models\DisciplineFile;
use App\Models\User;
use App\Services\ClickHouseService;
use App\Services\Discipline\DisciplineService;
use App\Services\Discipline\ReportScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class MobileDisciplineTest extends TestCase
{
    use RefreshDatabase;

    private User $author;

    private User $worker;

    private DisciplineAssignment $assignment;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        ClickHouseService::fake();
        Storage::fake('s3');
        $this->mock(ReportScanner::class)->shouldReceive('scan')->andReturnNull()->byDefault();
        $this->author = User::factory()->create(['role' => 'tormoznik']);
        $this->worker = User::factory()->instructor()->create();
        $this->assignment = $this->assign($this->worker);
        $this->token = $this->worker->createToken('mobile')->plainTextToken;
    }

    private function assign(User $user, array $extra = []): DisciplineAssignment
    {
        $result = app(DisciplineService::class)->create($this->author, array_replace([
            'operation_id' => (string) Str::uuid(), 'title' => 'Отчёт инструктора', 'instructions' => 'Выполнить задание',
            'due_date' => today()->addDay()->toDateString(), 'all_instructors' => false, 'assignee_ids' => [$user->id],
            'require_response' => true, 'require_report_file' => false, 'require_explanation_file' => false,
        ], $extra));

        return DisciplineAssignment::where('card_id', $result['card_id'])->firstOrFail();
    }

    private function api(string $method, string $path, array $data = [], ?string $token = null)
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => 'Bearer '.($token ?? $this->token)])->json($method, '/api/mobile/discipline'.$path, $data);
    }

    private function submission(int $version = 1): array
    {
        return ['operation_id' => (string) Str::uuid(), 'version' => (string) $version, 'response' => 'Работа выполнена'];
    }

    public function test_token_required_and_fresh_roles_and_activity_apply_to_all_endpoints(): void
    {
        $this->getJson('/api/mobile/discipline')->assertUnauthorized();
        foreach (['driver', 'admin', 'super_admin', 'student', 'dispatcher', 'operator', 'nachalnik', 'tormoznik'] as $role) {
            $token = User::factory()->create(['role' => $role])->createToken('mobile')->plainTextToken;
            $this->api('get', '', [], $token)->assertForbidden();
            $this->api('get', '/'.$this->assignment->id, [], $token)->assertForbidden();
            $this->api('post', '/'.$this->assignment->id.'/submit', $this->submission(), $token)->assertForbidden();
        }
        DB::table('users')->where('id', $this->worker->id)->update(['role' => 'driver']);
        $this->api('get', '')->assertForbidden();
        DB::table('users')->where('id', $this->worker->id)->update(['role' => 'instructor', 'is_active' => false]);
        $this->api('get', '')->assertForbidden();
    }

    public function test_list_is_personal_paginated_filtered_and_reports_are_private(): void
    {
        $other = User::factory()->instructor()->create();
        $hidden = $this->assign($other);
        $late = $this->assign($this->worker, ['due_date' => today()->subDays(2)->toDateString()]);
        $this->api('get', '?per_page=1')->assertOk()->assertJsonCount(1, 'assignments')->assertJsonPath('pagination.total', 2)->assertJsonPath('pagination.next_page', 2)->assertJsonPath('unread_count', 2)->assertHeader('Cache-Control', 'no-store, private');
        $this->api('get', '?overdue=1')->assertOk()->assertJsonCount(1, 'assignments')->assertJsonPath('assignments.0.id', (string) $late->id);
        $this->api('get', '?status=accepted')->assertJsonCount(0, 'assignments');
        $this->api('get', '?status=bad')->assertUnprocessable();
        $this->api('get', '?per_page=1000')->assertUnprocessable();
        foreach (['', '/reports'] as $suffix) {
            $this->api('get', '/'.$hidden->id.$suffix)->assertNotFound();
        }
        $this->api('post', '/'.$hidden->id.'/read')->assertNotFound();
        $this->api('post', '/'.$hidden->id.'/submit', $this->submission())->assertNotFound();
        $this->api('get', '/'.$this->assignment->id)->assertOk()->assertJsonPath('assignment.version', '1')->assertJsonPath('assignment.requirements.require_response', true)->assertJsonMissingPath('assignment.author.email');
    }

    public function test_read_marks_only_own_assignment_events_and_is_idempotent(): void
    {
        $other = $this->assign($this->worker);
        $this->api('post', '/'.$this->assignment->id.'/read')->assertOk();
        $this->api('post', '/'.$this->assignment->id.'/read')->assertOk();
        $this->api('get', '')->assertJsonPath('unread_count', 1);
        $this->assertSame(1, DB::table('discipline_notifications')->where('assignment_id', $other->id)->whereNull('read_at')->count());
    }

    public function test_mobile_submission_shares_web_review_and_exact_replay(): void
    {
        $uri = '/'.$this->assignment->id;
        $input = $this->submission();
        $first = $this->api('post', $uri.'/submit', $input)->assertOk()->assertJsonPath('assignment.status', 'in_review')->assertJsonPath('assignment.version', '2')->json('result');
        $this->api('post', $uri.'/submit', $input)->assertOk()->assertJsonPath('result.report_id', $first['report_id']);
        $this->assertDatabaseCount('discipline_reports', 1);
        $this->api('post', $uri.'/submit', array_replace($input, ['response' => 'Другое']))->assertConflict();
        app(DisciplineService::class)->review($this->author, $this->assignment->id, [
            'operation_id' => (string) Str::uuid(), 'version' => 2, 'report_id' => $first['report_id'], 'decision' => 'returned', 'comment' => 'Нужны подробности',
        ]);
        $this->api('get', $uri)->assertJsonPath('assignment.status', 'needs_revision')->assertJsonPath('reports.0.review_comment', 'Нужны подробности');
        $this->api('post', $uri.'/submit', $this->submission(1))->assertStatus(412);
        $second = $this->api('post', $uri.'/submit', $this->submission(3))->assertOk()->json('result');
        app(DisciplineService::class)->review($this->author, $this->assignment->id, [
            'operation_id' => (string) Str::uuid(), 'version' => 4, 'report_id' => $second['report_id'], 'decision' => 'accepted', 'comment' => '',
        ]);
        $this->api('get', $uri)->assertJsonPath('assignment.status', 'accepted')->assertJsonPath('reports.0.decision', 'accepted')->assertJsonPath('reports.1.decision', 'returned');
        $this->api('post', $uri.'/submit', $this->submission(5))->assertConflict();
        $this->api('post', $uri.'/review', [])->assertNotFound();
    }

    public function test_private_files_require_current_owner_token_and_are_not_exposed_as_urls(): void
    {
        $a = $this->assign($this->worker, ['require_report_file' => true]);
        $uri = '/'.$a->id;
        $this->api('post', $uri.'/submit', $this->submission())->assertUnprocessable();
        $pdf = UploadedFile::fake()->createWithContent('report.pdf', "%PDF-1.4\n%%EOF");
        $this->withHeaders(['Authorization' => 'Bearer '.$this->token, 'Accept' => 'application/json'])->post('/api/mobile/discipline'.$uri.'/submit', $this->submission() + ['report_files' => [$pdf]])->assertOk()->assertJsonPath('reports.0.files.0.mime', 'application/pdf')->assertJsonMissingPath('reports.0.files.0.path')->assertJsonMissingPath('reports.0.files.0.url');
        $file = DisciplineFile::firstOrFail();
        $this->api('get', '/files/'.$file->id)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Cache-Control', 'no-store, private');
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => ''])->getJson('/api/mobile/discipline/files/'.$file->id)->assertUnauthorized();
        $token = User::factory()->instructor()->create()->createToken('mobile')->plainTextToken;
        $this->api('get', '/files/'.$file->id, [], $token)->assertNotFound();
        $this->worker->update(['is_active' => false]);
        $this->api('get', '/files/'.$file->id)->assertUnauthorized();
    }

    public function test_scanner_failure_does_not_publish_mobile_report(): void
    {
        $this->mock(ReportScanner::class)->shouldReceive('scan')->andReturnUsing(fn () => abort(503));
        $pdf = UploadedFile::fake()->createWithContent('report.pdf', "%PDF-1.4\n%%EOF");
        $this->withHeaders(['Authorization' => 'Bearer '.$this->token])->postJson('/api/mobile/discipline/'.$this->assignment->id.'/submit', $this->submission() + ['report_files' => [$pdf]])->assertStatus(503);
        $this->assertDatabaseCount('discipline_reports', 0);
        $this->assertDatabaseCount('discipline_files', 0);
    }
}
