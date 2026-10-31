<?php

use App\Models\User;
use App\Models\UserProfile;
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
                $result = ['status' => 200, 'id' => $job()['id']];
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
$service = app(InstructorTrainingRequests::class);
$payload = fn () => ['operation_id' => (string) Str::uuid(), 'planning_month' => '2026-11', 'shift_type' => 'А2', 'participant_count' => 1, 'participants' => [$d->id]];
for ($i = 0; $i < 9; $i++) {
    $service->mutate($u, 'create', null, $payload());
}
$a = $payload();
$b = $payload();
$r = race([fn () => $service->mutate($u, 'create', null, $a), fn () => $service->mutate($u, 'create', null, $b)]);
$statuses = array_column($r, 'status');
sort($statuses);
check($statuses === [200, 422], 'Monthly limit race: '.json_encode($r));
check(DB::table('instructor_training_requests')->count() === 10, 'Monthly limit exceeded');
$retry = $payload();
$retry['planning_month'] = '2026-12';
$r = race([fn () => $service->mutate($u, 'create', null, $retry), fn () => $service->mutate($u, 'create', null, $retry)]);
check($r[0]['status'] === 200 && $r[1]['status'] === 200 && $r[0]['id'] === $r[1]['id'], 'Replay race: '.json_encode($r));
check(DB::table('instructor_training_requests')->count() === 11, 'Duplicate request');
echo "OK: PostgreSQL monthly limit and operation replay races.\n";
