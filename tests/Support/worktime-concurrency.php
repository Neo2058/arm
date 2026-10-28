<?php

/** Run only in an isolated disposable PostgreSQL database after the migrations. */
use App\Models\User;
use App\Models\WorkShift;
use App\Models\WorkTimeMonthSettings;
use App\Services\ClickHouseService;
use App\Services\Payroll\PersonalWorkShiftService;
use App\Services\Payroll\PersonalWorkTimeSettingsEditor;
use App\Services\Payroll\WorkTimeMutationService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (config('database.default') !== 'pgsql'
    || ! str_starts_with((string) config('database.connections.pgsql.database'), 'arm_worktime_test_')
    || ! str_starts_with((string) config('database.connections.pgsql.host'), '/tmp/arm-worktime-pg.')
    || ! function_exists('pcntl_fork')) {
    fwrite(STDERR, "Requires pcntl and an isolated local arm_worktime_test_* PostgreSQL database.\n");
    exit(1);
}
ClickHouseService::fake();

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
                $result = ['status' => 200, 'id' => $job()->id];
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

$user = User::factory()->create();
$input = ['shift_date' => '2026-10-27', 'started_at' => '2026-10-27 08:00', 'ended_at' => '2026-10-27 10:00'];
$uuid = (string) Str::uuid();
$job = fn () => app(PersonalWorkShiftService::class)->create($user, $input, $uuid);
$results = race([$job, $job]);
check($results[0]['status'] === 200 && $results[1]['status'] === 200 && $results[0]['id'] === $results[1]['id'], 'Retry race failed: '.json_encode($results));
check(WorkShift::where('user_id', $user->id)->count() === 1, 'Duplicate operation inserted');

$other = User::factory()->create();
$uuids = [(string) Str::uuid(), (string) Str::uuid()];
$results = race([
    fn () => app(PersonalWorkShiftService::class)->create($other, $input, $uuids[0]),
    fn () => app(PersonalWorkShiftService::class)->create($other, $input, $uuids[1]),
]);
$statuses = array_column($results, 'status');
sort($statuses);
check($statuses === [200, 422], 'Overlap race failed: '.json_encode($results));
check(WorkShift::where('user_id', $other->id)->count() === 1, 'Overlapping operations inserted');

$third = User::factory()->create();
$uuid = (string) Str::uuid();
$changed = array_replace($input, ['started_at' => '2026-10-27 11:00', 'ended_at' => '2026-10-27 13:00']);
$results = race([
    fn () => app(PersonalWorkShiftService::class)->create($third, $input, $uuid),
    fn () => app(PersonalWorkShiftService::class)->create($third, $changed, $uuid),
]);
$statuses = array_column($results, 'status');
sort($statuses);
check($statuses === [200, 409], 'Changed payload race failed: '.json_encode($results));
check(WorkShift::where('user_id', $third->id)->count() === 1, 'Changed operation inserted twice');
$editor = User::factory()->create();
$shift = app(PersonalWorkShiftService::class)->create($editor, $input, (string) Str::uuid());
$uuid = (string) Str::uuid();
$editJob = fn () => (object) app(WorkTimeMutationService::class)->run($editor, $uuid, 'shift.update', ['id' => $shift->id, 'input' => $changed, 'expected_revision' => 1],
    function ($user) use ($shift, $changed) {
        $updated = app(PersonalWorkShiftService::class)->update($user, $shift->id, $changed, 1);

        return ['id' => $updated->id, 'revision' => $updated->revision];
    });
$results = race([$editJob, $editJob]);
check(array_column($results, 'status') === [200, 200], 'Edit retry failed: '.json_encode($results));
check($shift->fresh()->revision === 2, 'Edit was applied twice');
$edit = fn ($uuid, $data) => (object) app(WorkTimeMutationService::class)->run($editor, $uuid, 'shift.update', ['id' => $shift->id, 'input' => $data, 'expected_revision' => 2],
    function ($user) use ($shift, $data) {
        $updated = app(PersonalWorkShiftService::class)->update($user, $shift->id, $data, 2);

        return ['id' => $updated->id, 'revision' => $updated->revision];
    });
$ids = [(string) Str::uuid(), (string) Str::uuid()];
$results = race([fn () => $edit($ids[0], $input), fn () => $edit($ids[1], $changed)]);
$statuses = array_column($results, 'status');
sort($statuses);
check($statuses === [200, 409], 'Revision edit race failed: '.json_encode($results));
check($shift->fresh()->revision === 3, 'Lost revision update');

$settingsUser = User::factory()->create();
$settingsJob = fn ($uuid, $rate) => (object) app(WorkTimeMutationService::class)->run($settingsUser, $uuid, 'settings.update', ['month' => '2026-10', 'rate' => $rate, 'expected_revision' => 0],
    function ($user) use ($rate) {
        $result = app(PersonalWorkTimeSettingsEditor::class)->update($user, '2026-10', ['line_rate_kopecks' => $rate], 0);

        return ['id' => $result['revision']];
    });
$ids = [(string) Str::uuid(), (string) Str::uuid()];
$results = race([fn () => $settingsJob($ids[0], 60000), fn () => $settingsJob($ids[1], 70000)]);
$statuses = array_column($results, 'status');
sort($statuses);
check($statuses === [200, 409], 'Settings revision race failed: '.json_encode($results));
check(WorkTimeMonthSettings::where('user_id', $settingsUser->id)->count() === 1, 'Duplicate month settings');
echo "OK: create UUID/overlap/conflicting payload, edit retry/revision, and settings revision races\n";

$absenceUser = User::factory()->create();
$period = ['type' => 'deviation', 'absence_kind' => 'sick', 'shift_date' => '2026-10-27', 'absence_end' => '2026-10-30'];
$ids = [(string) Str::uuid(), (string) Str::uuid()];
$results = race([
    fn () => app(PersonalWorkShiftService::class)->create($absenceUser, $period, $ids[0]),
    fn () => app(PersonalWorkShiftService::class)->create($absenceUser, $input, $ids[1]),
]);
$statuses = array_column($results, 'status');
sort($statuses);
check($statuses === [200, 422], 'Work/absence overlap race failed: '.json_encode($results));
check(WorkShift::where('user_id', $absenceUser->id)->count() === 1, 'Work and absence were both inserted');
echo "OK: work/absence mutual overlap race\n";
