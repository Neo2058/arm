<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckDeviceBinding;
use App\Http\Middleware\CheckDynamicBarrier;
use App\Models\JournalCrewNormative;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\ClickHouseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class InstructorTrainingAccountingTest extends TestCase
{
    use RefreshDatabase;

    private function person(string $role = 'instructor', string $column = '15'): User
    {
        $u = User::factory()->create(['role' => $role]);
        UserProfile::create(['user_id' => $u->id, 'column' => $column]);

        return $u;
    }

    private function send(User $u, string $path, array $body)
    {
        $this->withoutMiddleware([CheckDeviceBinding::class, CheckDynamicBarrier::class]);

        return $this->actingAs($u)->postJson('/journal/training-requests'.$path, $body);
    }

    private function card(User $u, array $members): array
    {
        return $this->send($u, '', ['operation_id' => (string) Str::uuid(), 'planning_month' => '2026-10', 'shift_type' => 'А2', 'participant_count' => count($members), 'participants' => array_map(fn ($m) => $m->id, $members)])->assertOk()->json();
    }

    private function periods(User $u, int $atz = 2, int $line = 3): array
    {
        $data = ['operation_id' => (string) Str::uuid(), 'atz_months' => $atz, 'atz_line_months' => $line];
        $this->send($u, '/periods', $data)->assertOk();

        return $data;
    }

    private function completion(array $members, string $date = '2026-01-31', string $type = 'atz'): array
    {
        return ['operation_id' => (string) Str::uuid(), 'version' => 1, 'normative_type' => $type, 'performed_on' => $date, 'attendees' => array_map(fn ($m) => $m->id, $members)];
    }

    private function assertNorm(array $expected): void
    {
        $query = JournalCrewNormative::query();
        foreach ($expected as $key => $value) {
            if (! in_array($key, ['last_date', 'next_date'], true)) {
                $query->where($key, $value);
            }
        }
        $row = $query->firstOrFail();
        foreach ($expected as $key => $value) {
            $actual = in_array($key, ['last_date', 'next_date'], true) ? $row->{$key}?->toDateString() : $row->{$key};
            $this->assertEquals($value, $actual);
        }
    }

    public function test_partial_attendance_dates_history_replay_and_freeze(): void
    {
        $u = $this->person();
        $a = $this->person('driver');
        $b = $this->person('driver');
        $card = $this->card($u, [$a, $b]);
        $this->periods($u, 1);
        $data = $this->completion([$a]);
        $first = $this->send($u, '/'.$card['id'].'/complete', $data)->assertOk()->json();
        $this->send($u, '/'.$card['id'].'/complete', $data)->assertOk()->assertExactJson($first);
        $this->send($u, '/'.$card['id'].'/complete', array_replace($data, ['normative_type' => 'atz_line']))->assertConflict();
        $this->assertNorm(['user_id' => $a->id, 'type' => 'atz', 'last_date' => '2026-01-31', 'next_date' => '2026-02-28']);
        $this->assertDatabaseMissing('journal_crew_normatives', ['user_id' => $b->id]);
        $this->assertDatabaseCount('instructor_training_completions', 1);
        $this->assertDatabaseCount('instructor_training_attendance', 1);
        $this->actingAs($u)->get('/journal/training-requests?month=2026-10')->assertOk()->assertSee('31.01.2026')->assertSee('28.02.2026')->assertSee('Занятие учтено');
        $this->actingAs($u)->deleteJson('/journal/training-requests/'.$card['id'], ['operation_id' => (string) Str::uuid(), 'version' => 2])->assertUnprocessable();
        $this->send($u, '/'.$card['id'].'/complete', array_replace($data, ['operation_id' => (string) Str::uuid(), 'version' => 2]))->assertUnprocessable();
    }

    public function test_period_recalculation_and_leap_year(): void
    {
        $u = $this->person();
        $a = $this->person('driver');
        $card = $this->card($u, [$a]);
        $this->periods($u, 2, 1);
        $this->send($u, '/'.$card['id'].'/complete', $this->completion([$a], '2024-01-31', 'atz_line'))->assertOk();
        $this->assertNorm(['user_id' => $a->id, 'type' => 'atz_line', 'next_date' => '2024-02-29']);
        $period = $this->periods($u, 2, 2);
        $this->send($u, '/periods', $period)->assertOk();
        $this->assertNorm(['user_id' => $a->id, 'last_date' => '2024-01-31', 'next_date' => '2024-03-31']);
        $this->assertDatabaseHas('instructor_training_completions', ['period_months' => 1]);
        $this->send($u, '/periods', array_replace($period, ['atz_months' => 25]))->assertUnprocessable();
    }

    public function test_dates_role_column_and_planned_participants(): void
    {
        $u = $this->person();
        $a = $this->person('driver');
        $outside = $this->person('driver');
        $card = $this->card($u, [$a]);
        foreach ([$this->completion([$outside]), $this->completion([$a], '2099-01-01'), $this->completion([$a], '2026-02-30'), $this->completion([])] as $data) {
            $this->send($u, '/'.$card['id'].'/complete', $data)->assertUnprocessable();
        }
        $this->send($this->person(), '/'.$card['id'].'/complete', $this->completion([$a]))->assertNotFound();
        $this->send($a, '/'.$card['id'].'/complete', $this->completion([$a]))->assertForbidden();
        UserProfile::where('user_id', $a->id)->update(['column' => '16']);
        $this->send($u, '/'.$card['id'].'/complete', $this->completion([$a]))->assertUnprocessable();
        $this->assertDatabaseCount('instructor_training_completions', 0);
        $this->assertDatabaseCount('journal_crew_normatives', 0);
    }

    public function test_older_session_does_not_regress_last_date(): void
    {
        $u = $this->person();
        $a = $this->person('driver');
        $this->periods($u, 2);
        $first = $this->card($u, [$a]);
        $this->send($u, '/'.$first['id'].'/complete', $this->completion([$a], '2026-09-30'))->assertOk();
        $older = $this->card($u, [$a]);
        $this->send($u, '/'.$older['id'].'/complete', $this->completion([$a], '2026-01-31'))->assertOk();
        $this->assertNorm(['last_date' => '2026-09-30', 'next_date' => '2026-11-30']);
        $this->assertDatabaseCount('instructor_training_completions', 2);
    }

    public function test_cross_instructor_norm_conflict_rolls_back(): void
    {
        $u = $this->person();
        $other = $this->person();
        $a = $this->person('driver');
        $card = $this->card($u, [$a]);
        JournalCrewNormative::create(['user_id' => $a->id, 'instructor_id' => $other->id, 'column' => '15', 'type' => 'atz', 'last_date' => '2026-01-01']);
        $this->send($u, '/'.$card['id'].'/complete', $this->completion([$a]))->assertUnprocessable();
        $this->assertDatabaseCount('instructor_training_completions', 0);
        $this->assertDatabaseCount('instructor_training_attendance', 0);
        $this->assertNorm(['instructor_id' => $other->id, 'last_date' => '2026-01-01']);
    }

    public function test_legacy_settings_recalculate_and_manual_atz_date_rejected(): void
    {
        ClickHouseService::fake();
        $u = $this->person();
        $a = $this->person('driver');
        $card = $this->card($u, [$a]);
        $this->send($u, '/'.$card['id'].'/complete', $this->completion([$a]))->assertOk();
        $settings = [];
        foreach (['kip_linia_bk', 'kip_linia_3', 'kip_linia_2', 'kip_linia_1', 'kip_manevry', 'kip_podem', 'kip_kru', 'kip_ars_r', 'kip_pnevmatika', 'kip_scep', 'atz', 'atz_line'] as $key) {
            $settings[$key.'_months'] = 2;
        }
        $this->actingAs($u)->post('/journal/settings', $settings)->assertRedirect();
        $this->assertNorm(['user_id' => $a->id, 'next_date' => '2026-03-31']);
        $this->actingAs($u)->postJson('/journal/standards', ['normatives' => [$a->id => ['atz' => ['last_date' => '2026-02-01']]]])->assertUnprocessable();
        $this->assertNorm(['user_id' => $a->id, 'last_date' => '2026-01-31']);
    }
}
