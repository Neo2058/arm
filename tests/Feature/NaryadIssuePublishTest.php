<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Http\Middleware\CheckDeviceBinding;
use App\Http\Middleware\CheckDynamicBarrier;
use App\Models\Naryad;
use App\Models\NaryadPersonFile;
use App\Models\User;
use App\Services\ClickHouseService;
use App\Services\Naryad\NaryadIssuePublisher;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class NaryadIssuePublishTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('s3');
        ClickHouseService::fake();
        $this->withoutMiddleware([
            CheckDeviceBinding::class,
            CheckDynamicBarrier::class,
        ]);
    }

    private function sampleNaryadText(): string
    {
        $pad = static fn (string $s): string => $s.str_repeat(' ', max(0, 90 - mb_strlen($s, 'UTF-8')));

        return implode("\n", [
            $pad('                 НАРЯД ЛОКОМОТИВНЫХ БРИГАД НА 02.09.2026, СРЕДА'),
            $pad(''),
            $pad('Мар. Состав   Фамилия                            Время Пункт'),
            $pad('24 27008      БОБРОВ В.А                           16.12ДПЧ   - 0.27 МР'),
            $pad('             ЛОГИНОВ Р.А.                          8.10БР    -16.25 ДПЧ'),
        ]);
    }

    private function issue(User $bobrov): Naryad
    {
        Storage::disk('s3')->put('naryads/full.pdf', '%PDF-1.4 general-naryad');
        Storage::disk('s3')->put('naryads/sources/2.txt', $this->sampleNaryadText());

        $naryad = Naryad::create([
            'title' => 'Наряд 02.09.2026',
            'file_path' => 'naryads/full.pdf',
            'source_files' => ['naryads/sources/2.txt'],
            'naryad_date' => '2026-09-02',
            'allowed_roles' => null,
        ]);

        $this->assertSame(1, app(NaryadIssuePublisher::class)->publish($naryad));
        $this->assertTrue(
            NaryadPersonFile::query()->where('naryad_id', $naryad->id)->where('user_id', $bobrov->id)->exists()
        );

        return $naryad->fresh();
    }

    public function test_publisher_writes_personal_file_named_after_user(): void
    {
        $bobrov = User::factory()->driver()->create(['name' => 'Бобров Виктор Александрович']);
        User::factory()->driver()->create(['name' => 'Петров Петр Петрович']);
        $naryad = $this->issue($bobrov);

        $file = NaryadPersonFile::query()->where('user_id', $bobrov->id)->first();
        $this->assertSame('БОБРОВ В.А.txt', $file->file_name);
        $contents = Storage::disk('s3')->get($file->file_path);
        $this->assertStringContainsString('БОБРОВ В.А', $contents);
        $this->assertStringContainsString('приёмка в отстое', $contents);
        $this->assertSame(0, NaryadPersonFile::query()->where('naryad_id', $naryad->id)->where('user_id', '!=', $bobrov->id)->count());
    }

    public function test_user_page_shows_only_own_file_and_general_pdf_url(): void
    {
        $bobrov = User::factory()->driver()->create(['name' => 'Бобров Виктор Александрович']);
        $naryad = $this->issue($bobrov);

        $html = $this->actingAs($bobrov)->get(route('naryady.index'))->assertOk()->getContent();
        $this->assertStringContainsString('pdf_url', $html);
        $this->assertStringContainsString('personal_url', $html);
        $this->assertStringContainsString('personal_name', $html);

        $pdf = $this->actingAs($bobrov)->get(route('naryady.pdf', $naryad));
        $pdf->assertOk();
        $pdf->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('%PDF-1.4 general-naryad', $pdf->streamedContent());
        $this->assertStringNotContainsString('9000', (string) $pdf->headers->get('Location'));

        $mine = $this->actingAs($bobrov)->get(route('naryady.mine', $naryad));
        $mine->assertOk();
        $this->assertStringContainsString('БОБРОВ В.А', $mine->streamedContent());
    }

    public function test_other_driver_cannot_download_someone_elses_file(): void
    {
        $bobrov = User::factory()->driver()->create(['name' => 'Бобров Виктор Александрович']);
        $other = User::factory()->driver()->create(['name' => 'Петров Петр Петрович']);
        $naryad = $this->issue($bobrov);

        $this->actingAs($other)->get(route('naryady.mine', $naryad))->assertNotFound();
    }

    public function test_role_restricted_issue_is_hidden(): void
    {
        $bobrov = User::factory()->driver()->create(['name' => 'Бобров Виктор Александрович']);
        $naryad = $this->issue($bobrov);
        $naryad->update(['allowed_roles' => [UserRole::INSTRUCTOR->value]]);

        $this->actingAs($bobrov)->get(route('naryady.pdf', $naryad))->assertForbidden();
        $this->actingAs($bobrov)->get(route('naryady.mine', $naryad))->assertForbidden();
    }

    public function test_republication_uses_new_paths_and_preserves_previous_objects(): void
    {
        $user = User::factory()->driver()->create(['name' => 'Бобров Виктор Александрович']);
        $naryad = $this->issue($user);
        $old = $naryad->personFiles()->sole();
        $bytes = Storage::disk('s3')->get($old->file_path);

        $this->assertSame(1, app(NaryadIssuePublisher::class)->publish($naryad));

        $new = $naryad->personFiles()->sole();
        $this->assertNotSame($old->file_path, $new->file_path);
        $this->assertSame($bytes, Storage::disk('s3')->get($old->file_path));
        $this->assertSame($bytes, Storage::disk('s3')->get($new->file_path));
        Storage::disk('s3')->assertExists('naryads/full.pdf');
        Storage::disk('s3')->assertExists('naryads/sources/2.txt');
    }

    public function test_failed_second_write_cannot_remove_or_overwrite_previous_issue(): void
    {
        $user = User::factory()->driver()->create(['name' => 'Бобров Виктор Александрович']);
        $naryad = $this->issue($user);
        $old = $naryad->personFiles()->sole();
        $disk = Storage::disk('s3');
        $bytes = $disk->get($old->file_path);
        User::factory()->driver()->create(['name' => 'Логинов Роман Александрович']);
        $proxy = Mockery::mock($disk);
        $writes = 0;
        $proxy->shouldReceive('put')->andReturnUsing(function ($path, $text) use ($disk, &$writes) {
            $disk->put($path, $text);

            return ++$writes < 2;
        });
        $proxy->shouldNotReceive('delete');
        Storage::shouldReceive('disk')->with('s3')->andReturn($proxy);

        try {
            app(NaryadIssuePublisher::class)->publish($naryad);
            $this->fail('Expected write failure');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Не удалось записать', $e->getMessage());
        }
        $this->assertSame(2, $writes);
        $this->assertSame($old->id, $naryad->personFiles()->sole()->id);
        $this->assertSame($bytes, $disk->get($old->file_path));
    }

    public function test_readback_mismatch_preserves_previous_issue(): void
    {
        $user = User::factory()->driver()->create(['name' => 'Бобров Виктор Александрович']);
        $naryad = $this->issue($user);
        $old = $naryad->personFiles()->sole();
        $disk = Storage::disk('s3');
        $bytes = $disk->get($old->file_path);
        $proxy = Mockery::mock($disk);
        $proxy->shouldReceive('get')->andReturnUsing(fn ($path) => str_contains($path, '/people/') ? 'corrupted bytes' : $disk->get($path));
        $proxy->shouldNotReceive('delete');
        Storage::shouldReceive('disk')->with('s3')->andReturn($proxy);

        try {
            app(NaryadIssuePublisher::class)->publish($naryad);
            $this->fail('Expected checksum failure');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Проверка записанного файла', $e->getMessage());
        }
        $this->assertSame($old->id, $naryad->personFiles()->sole()->id);
        $this->assertSame($bytes, $disk->get($old->file_path));
    }

    public function test_database_failure_rolls_back_references_without_deleting_files(): void
    {
        $user = User::factory()->driver()->create(['name' => 'Бобров Виктор Александрович']);
        $naryad = $this->issue($user);
        $old = $naryad->personFiles()->sole();
        $bytes = Storage::disk('s3')->get($old->file_path);
        DB::unprepared("CREATE TRIGGER reject_personal_insert BEFORE INSERT ON naryad_person_files BEGIN SELECT RAISE(ABORT, 'test insert failure'); END");
        try {
            try {
                app(NaryadIssuePublisher::class)->publish($naryad);
                $this->fail('Expected database failure');
            } catch (QueryException $e) {
                $this->assertStringContainsString('test insert failure', $e->getMessage());
            }
        } finally {
            DB::unprepared('DROP TRIGGER reject_personal_insert');
        }
        $this->assertSame($old->id, $naryad->personFiles()->sole()->id);
        $this->assertSame($bytes, Storage::disk('s3')->get($old->file_path));
    }

    public function test_outer_transaction_rollback_keeps_the_old_publication_readable(): void
    {
        $user = User::factory()->driver()->create(['name' => 'Бобров Виктор Александрович']);
        $naryad = $this->issue($user);
        $old = $naryad->personFiles()->sole();
        $bytes = Storage::disk('s3')->get($old->file_path);
        DB::beginTransaction();
        try {
            app(NaryadIssuePublisher::class)->publish($naryad);
            $newPath = $naryad->personFiles()->sole()->file_path;
            $this->assertNotSame($old->file_path, $newPath);
        } finally {
            DB::rollBack();
        }
        $this->assertSame($old->id, $naryad->personFiles()->sole()->id);
        $this->assertSame($bytes, Storage::disk('s3')->get($old->file_path));
        Storage::disk('s3')->assertExists($newPath);
    }

    public function test_unavailable_source_in_a_multi_source_issue_prevents_partial_publication(): void
    {
        $user = User::factory()->driver()->create(['name' => 'Бобров Виктор Александрович']);
        $naryad = $this->issue($user);
        $old = $naryad->personFiles()->sole();
        $before = Storage::disk('s3')->allFiles();
        $naryad->update(['source_files' => ['naryads/sources/2.txt', 'naryads/missing.txt']]);
        try {
            app(NaryadIssuePublisher::class)->publish($naryad);
            $this->fail('Expected missing source failure');
        } catch (Throwable $e) {
            $this->assertInstanceOf(RuntimeException::class, $e);
        }
        $this->assertSame($old->id, $naryad->personFiles()->sole()->id);
        $this->assertSame($before, Storage::disk('s3')->allFiles());
    }

    public function test_no_matches_do_not_clear_previous_publication(): void
    {
        $user = User::factory()->driver()->create(['name' => 'Бобров Виктор Александрович']);
        $naryad = $this->issue($user);
        $old = $naryad->personFiles()->sole();
        $user->update(['name' => 'Петров Петр Петрович']);
        $this->assertSame(0, app(NaryadIssuePublisher::class)->publish($naryad));
        $this->assertSame($old->id, $naryad->personFiles()->sole()->id);
        Storage::disk('s3')->assertExists($old->file_path);
    }

    public function test_invalid_source_does_not_replace_previous_publication(): void
    {
        $user = User::factory()->driver()->create(['name' => 'Бобров Виктор Александрович']);
        $naryad = $this->issue($user);
        $old = $naryad->personFiles()->sole();
        Storage::disk('s3')->put('naryads/invalid.txt', 'not a roster');
        $naryad->update(['source_files' => ['naryads/invalid.txt']]);
        try {
            app(NaryadIssuePublisher::class)->publish($naryad);
            $this->fail('Expected invalid source failure');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('не распознаны назначения', $e->getMessage());
        }
        $this->assertSame($old->id, $naryad->personFiles()->sole()->id);
        Storage::disk('s3')->assertExists($old->file_path);
    }
}
