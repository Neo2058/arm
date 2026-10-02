<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Http\Middleware\CheckDeviceBinding;
use App\Http\Middleware\CheckDynamicBarrier;
use App\Models\Naryad;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class NaryadViewerSearchTest extends TestCase
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

    private function putNaryad(array $overrides = []): Naryad
    {
        $path = $overrides['file_path'] ?? 'naryads/2.txt';
        if (! isset($overrides['skip_put'])) {
            Storage::disk('s3')->put($path, $this->sampleNaryadText());
        }
        unset($overrides['skip_put']);

        return Naryad::create(array_merge([
            'title' => 'Наряд 02.09.2026',
            'file_path' => $path,
            'naryad_date' => '2026-09-02',
            'allowed_roles' => null,
        ], $overrides));
    }

    public function test_search_returns_formatted_result_like_desktop_engine(): void
    {
        $instructor = User::factory()->instructor()->create();
        $naryad = $this->putNaryad();

        $res = $this->actingAs($instructor)
            ->postJson(route('naryady.search-people'), [
                'naryad_ids' => [$naryad->id],
                'queries' => ['БОБРОВ В.А', 'НЕТТАКОГО Н.Н'],
            ])
            ->assertOk();

        $text = $res->json('text');
        $this->assertIsString($text);
        $this->assertStringContainsString('Результаты поиска', $text);
        $this->assertStringContainsString('БОБРОВ В.А', $text);
        $this->assertStringContainsString('не найден', $text);
        $this->assertSame(1, $res->json('hits'));
        $this->assertContains('НЕТТАКОГО Н.Н', $res->json('missing'));
    }

    public function test_driver_cannot_search_naryad_of_other_role(): void
    {
        $driver = User::factory()->driver()->create();
        $naryad = $this->putNaryad([
            'allowed_roles' => [UserRole::INSTRUCTOR->value],
        ]);

        $this->actingAs($driver)
            ->postJson(route('naryady.search-people'), [
                'naryad_ids' => [$naryad->id],
                'queries' => ['БОБРОВ В.А'],
            ])
            ->assertStatus(422)
            ->assertJsonPath('hits', 0);
    }

    public function test_empty_queries_rejected(): void
    {
        $user = User::factory()->instructor()->create();
        $naryad = $this->putNaryad();

        $this->actingAs($user)
            ->postJson(route('naryady.search-people'), [
                'naryad_ids' => [$naryad->id],
                'queries' => [],
            ])
            ->assertStatus(422);
    }
}
