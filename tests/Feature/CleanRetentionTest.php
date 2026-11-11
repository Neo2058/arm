<?php

namespace Tests\Feature;

use App\Models\Naryad;
use App\Models\NaryadPersonFile;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CleanRetentionTest extends TestCase
{
    use RefreshDatabase;

    private function seedHistory(): Naryad
    {
        Storage::fake('s3');
        $user = User::factory()->create();
        $issue = Naryad::create([
            'title' => 'Старый наряд',
            'file_path' => 'naryads/old.pdf',
            'source_files' => ['naryads/source.txt'],
            'naryad_date' => now()->subDays(95)->toDateString(),
        ]);
        foreach (['naryads/old.pdf', 'naryads/source.txt', 'naryads/person.txt'] as $path) {
            Storage::disk('s3')->put($path, 'preserve these bytes');
        }
        NaryadPersonFile::create([
            'naryad_id' => $issue->id, 'user_id' => $user->id,
            'fio' => 'Сотрудник', 'file_name' => 'person.txt', 'file_path' => 'naryads/person.txt',
        ]);
        DB::table('naryad_assignments')->insert([
            'user_id' => $user->id, 'plan_date' => now()->subDays(95)->toDateString(), 'route_number' => '100',
        ]);

        return $issue;
    }

    public function test_non_dry_run_is_blocked_without_touching_history_or_storage(): void
    {
        $issue = $this->seedHistory();
        $disk = Storage::disk('s3');
        Storage::shouldReceive('disk')->never();
        $this->artisan('arm:clean-retention --months=3')
            ->expectsOutputToContain('Удаление нарядов, назначений и файлов отключено')
            ->assertFailed();
        $this->assertDatabaseHas('naryads', ['id' => $issue->id]);
        $this->assertDatabaseCount('naryad_person_files', 1);
        $this->assertDatabaseCount('naryad_assignments', 1);
        $this->assertSame('preserve these bytes', $disk->get('naryads/person.txt'));
    }

    public function test_dry_run_only_counts_rows_and_does_not_access_minio(): void
    {
        $issue = $this->seedHistory();
        $disk = Storage::disk('s3');
        Storage::shouldReceive('disk')->never();
        $this->artisan('arm:clean-retention --months=3 --dry-run')
            ->expectsOutputToContain('Наряды: 1')
            ->expectsOutputToContain('Персональные записи: 1')
            ->expectsOutputToContain('naryad_assignments: 1')
            ->assertSuccessful();
        $this->assertDatabaseHas('naryads', ['id' => $issue->id]);
        $this->assertDatabaseCount('naryad_assignments', 1);
        foreach (['naryads/old.pdf', 'naryads/source.txt', 'naryads/person.txt'] as $path) {
            $this->assertSame('preserve these bytes', $disk->get($path));
        }
    }

    public function test_custom_window_is_report_only(): void
    {
        $issue = $this->seedHistory();
        $issue->update(['naryad_date' => now()->subDays(40)]);
        $this->artisan('arm:clean-retention --days=30 --dry-run')->expectsOutputToContain('Наряды: 1')->assertSuccessful();
        $this->assertDatabaseHas('naryads', ['id' => $issue->id]);
    }

    public function test_invalid_window_is_rejected(): void
    {
        foreach (['0', '-1', 'abc', '1.5', '999999'] as $days) {
            $this->artisan('arm:clean-retention', ['--days' => $days, '--dry-run' => true])->assertFailed();
        }
    }

    public function test_cleanup_is_not_automatically_scheduled(): void
    {
        $this->artisan('schedule:list')->assertSuccessful();
        foreach (app(Schedule::class)->events() as $event) {
            $this->assertStringNotContainsString('arm:clean-retention', (string) $event->command);
        }
    }
}
