<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Http\Middleware\CheckDeviceBinding;
use App\Http\Middleware\CheckDynamicBarrier;
use App\Models\InstructorNaryadFile;
use App\Models\InstructorQueryList;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class InstructorNaryadSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            CheckDeviceBinding::class,
            CheckDynamicBarrier::class,
        ]);
    }

    public function test_instructor_can_open_search_page(): void
    {
        $instructor = User::factory()->instructor()->create();
        $this->actingAs($instructor)->get(route('journal.naryad-search'))->assertOk();
    }

    public function test_driver_cannot_open_search_page(): void
    {
        $driver = User::factory()->driver()->create();
        $this->actingAs($driver)->get(route('journal.naryad-search'))->assertForbidden();
    }

    public function test_files_are_scoped_to_owner(): void
    {
        $a = User::factory()->instructor()->create();
        $b = User::factory()->instructor()->create();
        InstructorNaryadFile::create([
            'user_id' => $a->id,
            'original_name' => 'mine.txt',
            'path' => 'x',
            'assignments' => [],
            'meta' => [],
        ]);

        $this->actingAs($b)->get(route('journal.naryad-search'))
            ->assertOk()
            ->assertDontSee('mine.txt');
    }

    public function test_instructor_can_upload_query_list_and_run_empty_naryads_fails(): void
    {
        $instructor = User::factory()->instructor()->create();
        $file = UploadedFile::fake()->createWithContent('names.txt', "БОБРОВ В.А\n");

        $this->actingAs($instructor)
            ->post(route('journal.naryad-search.queries'), ['queries' => [$file]])
            ->assertRedirect();

        $this->assertDatabaseHas('instructor_query_lists', [
            'user_id' => $instructor->id,
            'original_name' => 'names.txt',
        ]);

        $this->actingAs($instructor)
            ->post(route('journal.naryad-search.run'))
            ->assertSessionHasErrors('run');
    }

    public function test_search_uses_uploaded_naryad_and_queries(): void
    {
        $instructor = User::factory()->instructor()->create();
        $naryad = UploadedFile::fake()->createWithContent('2.txt', implode("\n", [
            '                 НАРЯД ЛОКОМОТИВНЫХ БРИГАД НА 02.09.2026, СРЕДА',
            '24 27008      БОБРОВ В.А                           16.12ДПЧ   - 0.27 МР',
        ]));
        $names = UploadedFile::fake()->createWithContent('q.txt', "БОБРОВ В.А\n");

        $this->actingAs($instructor)
            ->post(route('journal.naryad-search.naryads'), ['naryads' => [$naryad]])
            ->assertRedirect();
        $this->actingAs($instructor)
            ->post(route('journal.naryad-search.queries'), ['queries' => [$names]])
            ->assertRedirect();

        $this->actingAs($instructor)
            ->post(route('journal.naryad-search.run'))
            ->assertRedirect()
            ->assertSessionHas('instructor_naryad_result');

        $text = session('instructor_naryad_result');
        $this->assertStringContainsString('БОБРОВ В.А', $text);
    }
}
