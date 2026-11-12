<?php

/** Only an empty disposable local PostgreSQL database; never production. */
use App\Models\Document;
use App\Models\InstructionCategory;
use App\Models\User;
use App\Services\ClickHouseService;
use App\Services\DocumentPublishing;
use App\Services\DocumentRevisions;
use App\Services\InstructionSignatures;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$host = (string) config('database.connections.pgsql.host');
if (config('database.default') !== 'pgsql' || config('app.env') !== 'testing'
    || ! str_starts_with((string) config('database.connections.pgsql.database'), 'arm_document_test_')
    || ! str_starts_with($host, '/private/tmp/arm-document-pg.')
    || ! function_exists('pcntl_fork')) {
    throw new RuntimeException('Requires isolated arm_document_test_* DB and private temporary socket.');
}
set_exception_handler(function (Throwable $error) {
    fwrite(STDERR, 'PG_DOCUMENT_TEST_FAILED '.$error::class."\n");
    exit(1);
});
Http::fake();
Queue::fake();
ClickHouseService::fake();
config(['filesystems.disks.s3' => ['driver' => 'local', 'root' => dirname($host).'/test-files', 'throw' => true]]);
Artisan::call('migrate', ['--force' => true]);

function ensure(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function race(array $jobs): array
{
    DB::disconnect();
    $barrier = tempnam(sys_get_temp_dir(), 'document-start-');
    $children = [];
    foreach ($jobs as $job) {
        $output = tempnam(sys_get_temp_dir(), 'document-race-');
        $pid = pcntl_fork();
        ensure($pid >= 0, 'fork failed');
        if ($pid === 0) {
            DB::purge();
            while (is_file($barrier)) {
                clearstatcache(true, $barrier);
                usleep(1000);
            }
            try {
                $job();
                $result = 200;
            } catch (ValidationException) {
                $result = 422;
            } catch (ModelNotFoundException) {
                $result = 404;
            } catch (Throwable $error) {
                if (! method_exists($error, 'getStatusCode')) {
                    fwrite(STDERR, $error::class."\n");
                }
                $result = method_exists($error, 'getStatusCode') ? $error->getStatusCode() : 500;
            }
            file_put_contents($output, (string) $result);
            exit(0);
        }
        $children[$pid] = $output;
    }
    unlink($barrier);
    $results = [];
    foreach ($children as $pid => $output) {
        pcntl_waitpid($pid, $status);
        ensure(pcntl_wexitstatus($status) === 0, 'child failed');
        $results[] = (int) file_get_contents($output);
        unlink($output);
    }
    DB::purge();
    sort($results);

    return $results;
}

$admin = User::factory()->create(['role' => 'super_admin']);
$driver = User::factory()->driver()->create();
auth()->setUser($admin);
$category = InstructionCategory::create(['name' => 'Test', 'slug' => 'test', 'is_active' => true]);
foreach (['old.pdf' => 'old', 'a.pdf' => 'edition a', 'b.pdf' => 'edition b', 'copy-a.pdf' => 'same bytes', 'copy-b.pdf' => 'same bytes', 'sign.pdf' => 'sign original'] as $path => $bytes) {
    Storage::disk('s3')->put($path, $bytes);
}
$original = Document::create(['title' => 'Test original', 'file_path' => 'old.pdf', 'instruction_category_id' => $category->id]);
$payload = ['revision_label' => '2', 'effective_on' => today()->toDateString(), 'replacement_reason' => 'Test', 'replacement_confirmed' => true, 'references_reviewed' => true];
$results = race([
    fn () => app(DocumentRevisions::class)->publish($admin, $original->id, $payload + ['file_path' => 'a.pdf']),
    fn () => app(DocumentRevisions::class)->publish($admin, $original->id, $payload + ['file_path' => 'b.pdf']),
]);
ensure($results === [200, 422], 'revision race: '.json_encode($results));
ensure(Document::where('replaces_document_id', $original->id)->count() === 1, 'duplicate successor');
ensure(Document::active()->count() === 1 && $original->fresh()->withdrawn_at !== null, 'revision activation mismatch');
echo "PG_REVISION_RACE_PASS\n";

$results = race([
    fn () => app(DocumentPublishing::class)->save(['title' => 'Copy A', 'file_path' => 'copy-a.pdf']),
    fn () => app(DocumentPublishing::class)->save(['title' => 'Copy B', 'file_path' => 'copy-b.pdf']),
]);
ensure($results === [200, 422], 'duplicate upload race: '.json_encode($results));
ensure(Document::where('file_sha256', hash('sha256', 'same bytes'))->count() === 1, 'duplicate content registered');
echo "PG_DUPLICATE_RACE_PASS\n";

$signDocument = Document::create(['title' => 'Sign race', 'file_path' => 'sign.pdf', 'instruction_category_id' => $category->id]);
$results = race([
    fn () => app(DocumentRevisions::class)->withdraw($admin, $signDocument->id, ['reason' => 'Test', 'confirmed' => true]),
    fn () => app(InstructionSignatures::class)->sign($driver, $signDocument->id, ['confirmed' => true]),
]);
ensure(in_array($results, [[200, 200], [200, 404]], true), 'withdraw/sign race: '.json_encode($results));
ensure($signDocument->fresh()->withdrawn_at !== null, 'withdrawal missing');
try {
    app(InstructionSignatures::class)->sign($driver, $signDocument->id, ['confirmed' => true]);
    throw new RuntimeException('signed after withdrawal');
} catch (NotFoundHttpException|\Illuminate\Database\Eloquent\ModelNotFoundException) {
}
echo "PG_WITHDRAW_SIGN_RACE_PASS\n";
echo "POSTGRES_DOCUMENT_MIGRATIONS_AND_RACES_PASS\n";
