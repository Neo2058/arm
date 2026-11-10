<?php

namespace Tests\Feature;

use App\Models\Naryad;
use App\Models\NaryadPersonFile;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CleanRetentionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('s3');
    }

    public function test_cleans_naryads_older_than_retention_window_and_preserves_recent(): void
    {
        $oldDate = Carbon::now()->subDays(95);
        $recentDate = Carbon::now()->subDays(20);

        // Old naryad and files
        Storage::disk('s3')->put('naryads/old.pdf', '%PDF-1.4 old');
        Storage::disk('s3')->put('naryads/old_person.pdf', '%PDF-1.4 old-person');

        $oldNaryad = Naryad::create([
            'title' => 'Старый наряд',
            'file_path' => 'naryads/old.pdf',
            'source_files' => ['naryads/old.pdf'],
            'naryad_date' => $oldDate,
            'is_processed' => true,
        ]);

        $user = User::factory()->create();
        NaryadPersonFile::create([
            'naryad_id' => $oldNaryad->id,
            'user_id' => $user->id,
            'fio' => 'Иванов И.И.',
            'file_name' => 'old_person.pdf',
            'file_path' => 'naryads/old_person.pdf',
        ]);

        // Recent naryad and files
        Storage::disk('s3')->put('naryads/recent.pdf', '%PDF-1.4 recent');
        Storage::disk('s3')->put('naryads/recent_person.pdf', '%PDF-1.4 recent-person');

        $recentNaryad = Naryad::create([
            'title' => 'Свежий наряд',
            'file_path' => 'naryads/recent.pdf',
            'source_files' => ['naryads/recent.pdf'],
            'naryad_date' => $recentDate,
            'is_processed' => true,
        ]);

        NaryadPersonFile::create([
            'naryad_id' => $recentNaryad->id,
            'user_id' => $user->id,
            'fio' => 'Иванов И.И.',
            'file_name' => 'recent_person.pdf',
            'file_path' => 'naryads/recent_person.pdf',
        ]);

        // Run dry-run first
        $this->artisan('arm:clean-retention --months=3 --dry-run')
            ->assertExitCode(0);

        $this->assertDatabaseHas('naryads', ['id' => $oldNaryad->id]);
        $this->assertDatabaseHas('naryads', ['id' => $recentNaryad->id]);
        Storage::disk('s3')->assertExists('naryads/old.pdf');

        // Run actual cleanup (3 months = 90 days)
        $this->artisan('arm:clean-retention --months=3')
            ->assertExitCode(0);

        // Old records & files pruned
        $this->assertDatabaseMissing('naryads', ['id' => $oldNaryad->id]);
        $this->assertDatabaseMissing('naryad_person_files', ['file_path' => 'naryads/old_person.pdf']);
        Storage::disk('s3')->assertMissing('naryads/old.pdf');
        Storage::disk('s3')->assertMissing('naryads/old_person.pdf');

        // Recent records & files intact
        $this->assertDatabaseHas('naryads', ['id' => $recentNaryad->id]);
        $this->assertDatabaseHas('naryad_person_files', ['file_path' => 'naryads/recent_person.pdf']);
        Storage::disk('s3')->assertExists('naryads/recent.pdf');
        Storage::disk('s3')->assertExists('naryads/recent_person.pdf');
    }

    public function test_cleans_custom_days_retention(): void
    {
        $date40DaysAgo = Carbon::now()->subDays(40);
        $naryad = Naryad::create([
            'title' => 'Наряд 40 дней назад',
            'file_path' => 'naryads/40.pdf',
            'naryad_date' => $date40DaysAgo,
            'is_processed' => true,
        ]);

        // With --days=30, 40 days ago is cleaned
        $this->artisan('arm:clean-retention --days=30')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('naryads', ['id' => $naryad->id]);
    }
}
