<?php

/** Standalone integration check. Dedicated disposable PostgreSQL only, no production access. */
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Models\DisciplineAssignment;
use App\Models\User;
use App\Services\ClickHouseService;
use App\Services\Discipline\DisciplineService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

if (config('database.default') !== 'pgsql' || ! str_starts_with(config('database.connections.pgsql.database'), 'arm_discipline_test_') || ! function_exists('pcntl_fork')) {
    fwrite(STDERR, "Requires pcntl and a PostgreSQL database named arm_discipline_test_*.\n");
    exit(1);
}
ClickHouseService::fake();
$manager = User::factory()->create(['role' => 'tormoznik']);
$chief = User::factory()->create(['role' => 'nachalnik']);
$user = User::factory()->instructor()->create();
$card = app(DisciplineService::class)->create($manager, [
    'operation_id' => (string) Str::uuid(), 'title' => 'Concurrency test', 'instructions' => 'Report', 'due_date' => '2026-12-01',
    'all_instructors' => false, 'assignee_ids' => [$user->id], 'require_response' => true, 'require_report_file' => false, 'require_explanation_file' => false,
]);
$a = DisciplineAssignment::where('card_id', $card['card_id'])->firstOrFail();

function concurrent(array $actions): array
{
    DB::disconnect();
    $children = [];
    foreach ($actions as $action) {
        $path = tempnam(sys_get_temp_dir(), 'discipline-race-');
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('fork failed');
        }
        if ($pid === 0) {
            DB::purge();
            try {
                $result = ['status' => 200, 'result' => $action()];
            } catch (Throwable $e) {
                $result = ['status' => method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 500, 'class' => $e::class];
            }
            file_put_contents($path, json_encode($result, JSON_THROW_ON_ERROR));
            exit(0);
        }
        $children[$pid] = $path;
    }
    $results = [];
    foreach ($children as $pid => $path) {
        pcntl_waitpid($pid, $status);
        $results[] = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        unlink($path);
    }
    DB::purge();

    return $results;
}
function check(bool $ok, string $message): void
{
    if (! $ok) {
        throw new RuntimeException($message);
    }
    echo "PASS $message\n";
}
$submission = ['operation_id' => (string) Str::uuid(), 'version' => 1, 'response' => 'Done'];
$submit = fn () => app(DisciplineService::class)->submit(User::findOrFail($user->id), $a->id, $submission, []);
$result = concurrent([$submit, $submit]);
check(array_column($result, 'status') === [200, 200] && $result[0]['result'] === $result[1]['result'], 'concurrent exact retries return one result');
check($a->reports()->count() === 1 && $a->fresh()->version === 2, 'one report and one version increment');
$report = $a->reports()->firstOrFail();
$review = ['version' => 2, 'report_id' => $report->id, 'decision' => 'accepted', 'comment' => ''];
$result = concurrent([
    fn () => app(DisciplineService::class)->review(User::findOrFail($manager->id), $a->id, $review + ['operation_id' => (string) Str::uuid()]),
    fn () => app(DisciplineService::class)->review(User::findOrFail($chief->id), $a->id, $review + ['operation_id' => (string) Str::uuid()]),
]);
$statuses = array_column($result, 'status');
sort($statuses);
check($statuses === [200, 412], 'one of two simultaneous reviewers succeeds, stale decision is rejected');
check($a->fresh()->status === 'accepted' && $a->fresh()->version === 3, 'accepted state and history remain consistent');
// Check submit vs review lock ordering: notification FK references a concurrently locked chief.
$card = app(DisciplineService::class)->create($manager, [
    'operation_id' => (string) Str::uuid(), 'title' => 'Lock order', 'instructions' => 'Report', 'due_date' => '2026-12-01',
    'all_instructors' => false, 'assignee_ids' => [$user->id], 'require_response' => true, 'require_report_file' => false, 'require_explanation_file' => false,
]);
$b = DisciplineAssignment::where('card_id', $card['card_id'])->firstOrFail();
$result = concurrent([
    fn () => app(DisciplineService::class)->submit(User::findOrFail($user->id), $b->id, ['operation_id' => (string) Str::uuid(), 'version' => 1, 'response' => 'Done'], []),
    fn () => app(DisciplineService::class)->review(User::findOrFail($chief->id), $b->id, ['operation_id' => (string) Str::uuid(), 'version' => 1, 'report_id' => 999999999, 'decision' => 'accepted', 'comment' => '']),
]);
check(! in_array(500, array_column($result, 'status'), true), 'submit/review race does not deadlock notification recipients');
