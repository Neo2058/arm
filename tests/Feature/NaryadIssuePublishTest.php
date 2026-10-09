<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Http\Middleware\CheckDeviceBinding;
use App\Http\Middleware\CheckDynamicBarrier;
use App\Models\Naryad;
use App\Models\NaryadPersonFile;
use App\Models\User;
use App\Services\Naryad\NaryadIssuePublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class NaryadIssuePublishTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('s3');
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
        $this->assertStringContainsString('БОБРОВ В.А', Storage::disk('s3')->get($file->file_path));
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
}
