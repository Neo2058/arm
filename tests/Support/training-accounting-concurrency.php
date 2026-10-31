<?php

use App\Models\JournalCrewNormative;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\Journal\InstructorTrainingAccounting;
use App\Services\Journal\InstructorTrainingRequests;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (config('database.default') !== 'pgsql' || config('database.connections.pgsql.database') !== 'arm_training_test' || ! str_starts_with(config('database.connections.pgsql.host'), '/private/tmp/arm-training-pg.') || ! function_exists('pcntl_fork')) {
    exit('Requires isolated arm_training_test PostgreSQL and pcntl.');
}
function race(array $jobs): array
{
    DB::disconnect();
    $barrier = tempnam(sys_get_temp_dir(), 'worktime-start-');
    $children = [];
    foreach ($jobs as $job) {
        $output = tempnam(sys_get_temp_dir(), 'worktime-race-');
        $pid = pcntl_fork();
        if ($pid < 0) {
            throw new RuntimeException('fork failed');
        }
        if ($pid === 0) {
            DB::purge();
            while (is_file($barrier)) {
                clearstatcache(true, $barrier);
                usleep(1000);
            }
            try {
                $result = ['status' => 200, 'id' => $job()];
            } catch (ValidationException) {
                $result = ['status' => 422];
            } catch (Throwable $e) {
                $result = ['status' => method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 500, 'class' => $e::class];
            }
            file_put_contents($output, json_encode($result, JSON_THROW_ON_ERROR));
            exit(0);
        }
        $children[$pid] = $output;
    }
    unlink($barrier);
    $results = [];
    foreach ($children as $pid => $output) {
        pcntl_waitpid($pid, $status);
        if (pcntl_wexitstatus($status) !== 0) {
            throw new RuntimeException('Child failed');
        }
        $results[] = json_decode(file_get_contents($output), true, 512, JSON_THROW_ON_ERROR);
        unlink($output);
    }
    DB::purge();

    return $results;
}

function check(bool $ok, string $message): void
{
    if (! $ok) {
        throw new RuntimeException($message);
    }
}

$u = User::factory()->create(['role' => 'instructor']);
UserProfile::create(['user_id' => $u->id, 'column' => '15']);
$d = User::factory()->create(['role' => 'driver']);
UserProfile::create(['user_id' => $d->id, 'column' => '15']);
$requests = app(InstructorTrainingRequests::class);
$accounting = app(InstructorTrainingAccounting::class);
function card($u, $d, $requests)
{
    return $requests->mutate($u, 'create', null, ['operation_id' => (string) Str::uuid(), 'planning_month' => '2026-10', 'shift_type' => 'А2', 'participant_count' => 1, 'participants' => [$d->id]])['id'];
}
$body = fn () => ['operation_id' => (string) Str::uuid(), 'version' => 1, 'performed_on' => '2026-01-31', 'normative_type' => 'atz', 'attendees' => [$d->id]];
$id = card($u, $d, $requests);
$data = $body();
$r = race([fn () => $accounting->complete($u, $id, $data), fn () => $accounting->complete($u, $id, $data)]);
check($r[0]['status'] === 200 && $r[1]['status'] === 200 && $r[0]['id'] === $r[1]['id'], 'Completion replay race: '.json_encode($r));
check(DB::table('instructor_training_completions')->count() === 1, 'Duplicate completed session');
$id = card($u, $d, $requests);
$a = $body();
$b = $body();
$r = race([fn () => $accounting->complete($u, $id, $a), fn () => $accounting->complete($u, $id, $b)]);
$statuses = array_column($r, 'status');
sort($statuses);
check($statuses === [200, 422], 'Independent completion race: '.json_encode($r));
$id = card($u, $d, $requests);
$data = $body();
$settings = ['operation_id' => (string) Str::uuid(), 'atz_months' => 2, 'atz_line_months' => 3];
$r = race([fn () => $accounting->complete($u, $id, $data), fn () => $accounting->periods($u, $settings)]);
check($r[0]['status'] === 200 && $r[1]['status'] === 200, 'Settings/completion race: '.json_encode($r));
$norm = JournalCrewNormative::where('user_id', $d->id)->where('type', 'atz')->firstOrFail();
check($norm->next_date->toDateString() === '2026-03-31','Date inconsistent with settings');
echo "OK: PostgreSQL completion replay, independent confirmation and periods/completion races.\n";
