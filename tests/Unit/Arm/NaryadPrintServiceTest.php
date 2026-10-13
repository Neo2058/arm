<?php

namespace Tests\Unit\Arm;

use App\Models\ArmAppointment;
use App\Models\ArmCode;
use App\Models\ArmPersonnel;
use App\Models\ArmRoster;
use App\Models\ArmShiftBreakdown;
use App\Models\ArmSignature;
use App\Models\DeviationsCatalog;
use App\Models\NaryadAssignment;
use App\Models\NaryadNorm;
use App\Models\NaryadQuota;
use App\Models\ScheduleType;
use App\Models\User;
use App\Services\Arm\NaryadPrintService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NaryadPrintServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        NaryadNorm::create([
            'year_hours' => 5000,
            'month_hours' => 400,
            'week_hours' => 80,
            'min_rest_hours' => 8,
        ]);
    }

    public function test_full_naryad_lists_assigned_pair_and_vacant_shift(): void
    {
        $this->seedGraph();
        $mash = $this->driver('ИВАНОВ И.И.', 'МШ', '2001');
        $pom = $this->driver('ПЕТРОВ П.П.', 'П/М', '2002');
        NaryadAssignment::create([
            'user_id' => $mash->id,
            'plan_date' => '2026-04-10',
            'route_number' => '25 (2-ранняя)',
        ]);
        NaryadAssignment::create([
            'user_id' => $pom->id,
            'plan_date' => '2026-04-10',
            'route_number' => '25 (2-ранняя)',
        ]);

        $sheet = app(NaryadPrintService::class)->build('2026-04-10', NaryadPrintService::KIND_FULL);

        $this->assertStringContainsString('НАРЯД ЛОКОМОТИВНЫХ БРИГАД НА 10.04.2026', $sheet['title']);
        $this->assertNull($sheet['message']);

        $row25 = collect($sheet['rows'])->first(fn ($row) => str_contains((string) $row['route_label'], '25'));
        $this->assertNotNull($row25);
        $this->assertStringContainsString('ИВАНОВ', $row25['machinist']);
        $this->assertStringContainsString('ПЕТРОВ', $row25['assistant']);
        $this->assertStringContainsString('5.30', $row25['time']);
        $this->assertStringContainsString('14.20', $row25['time']);

        $vacant = collect($sheet['rows'])->first(fn ($row) => str_contains((string) $row['route_label'], '26'));
        $this->assertNotNull($vacant);
        $this->assertTrue($vacant['vacant']);
        $this->assertStringContainsString('. . .', $vacant['machinist']);
    }

    public function test_extract_keeps_first_and_night_and_hides_other_shifts(): void
    {
        $this->seedGraph();
        $mash = $this->driver('СИДОРОВ С.С.', 'МШ', '2003');
        NaryadAssignment::create([
            'user_id' => $mash->id,
            'plan_date' => '2026-04-10',
            'route_number' => '25 (1-с ночи)',
        ]);

        $sheet = app(NaryadPrintService::class)->build('2026-04-10', NaryadPrintService::KIND_EXTRACT);

        $this->assertStringContainsString('ВЫПИСКА ИЗ НАРЯДА', $sheet['title']);
        $labels = collect($sheet['rows'])->pluck('route_label')->filter()->implode(' ');
        $this->assertStringContainsString('25', $labels);
        $this->assertStringContainsString('10', $labels);
        $this->assertStringNotContainsString('26', $labels);

        $first = collect($sheet['rows'])->first(fn ($row) => $row['shift'] === '1');
        $this->assertNotNull($first);
        $this->assertStringContainsString('5.30', $first['time']);
        $this->assertStringNotContainsString('14.20', $first['time']);

        $night = collect($sheet['rows'])->first(fn ($row) => $row['shift'] === '3+');
        $this->assertNotNull($night);
        $this->assertStringNotContainsString('22.00', $night['time']);
        $this->assertStringContainsString('6.10', $night['time']);
    }

    public function test_star_note_skips_vacant_slot_and_absence_goes_to_footer(): void
    {
        $this->seedGraph();
        ArmShiftBreakdown::create([
            'graph_code' => '01',
            'route_code' => '27',
            'shift_code' => '2',
            'sequence' => '1',
            'start_hours' => 8.00,
            'end_hours' => 16.00,
            'notes' => '*служебная',
        ]);
        DeviationsCatalog::create([
            'name' => 'Выходной',
            'sys_key' => 'rest',
            'short_code' => 'Е',
        ]);
        $rest = $this->driver('КОЗЛОВ К.К.', 'МШ', '2004');
        NaryadAssignment::create([
            'user_id' => $rest->id,
            'plan_date' => '2026-04-10',
            'route_number' => 'Е',
        ]);

        $sheet = app(NaryadPrintService::class)->build('2026-04-10');

        $this->assertFalse(collect($sheet['rows'])->contains(fn ($row) => str_contains((string) $row['route_label'], '27')));
        $this->assertNotEmpty($sheet['absences']);
        $this->assertStringContainsString('КОЗЛОВ', $sheet['absences'][0]['names'][0]);
    }

    public function test_vacation_and_new_appointment_marks(): void
    {
        $this->seedGraph();
        $mash = $this->driver('НОВЫЙ Н.Н.', 'МШ', '2005');
        ArmAppointment::create([
            'user_id' => $mash->id,
            'tab_number' => '2005',
            'appointed_on' => '2026-01-15',
            'position_code' => 'МШ',
        ]);
        NaryadAssignment::create([
            'user_id' => $mash->id,
            'plan_date' => '2026-04-01',
            'route_number' => 'ОД',
        ]);
        NaryadAssignment::create([
            'user_id' => $mash->id,
            'plan_date' => '2026-04-10',
            'route_number' => '25 (2-ранняя)',
        ]);

        $sheet = app(NaryadPrintService::class)->build('2026-04-10');
        $row = collect($sheet['rows'])->first(fn ($r) => str_contains((string) $r['machinist'], 'НОВЫЙ'));
        $this->assertNotNull($row);
        $this->assertStringContainsString('отп.', $row['machinist']);
        $this->assertStringContainsString('!', $row['machinist']);
    }

    public function test_missing_calendar_returns_message(): void
    {
        $sheet = app(NaryadPrintService::class)->build('2026-04-10');
        $this->assertNotNull($sheet['message']);
        $this->assertSame([], $sheet['rows']);
    }

    public function test_rasst_consist_splits_across_shifts_like_app_nar(): void
    {
        $this->seedGraph();
        ArmCode::create([
            'book_code' => '03',
            'code' => '51 ш',
            'name' => '27018-27017',
            'nom' => '30',
        ]);
        ArmRoster::create([
            'roster_date' => '2026-04-10',
            'kind' => ArmRoster::KIND_PRIMARY,
            'route_number' => '25',
            'instrument' => '51 ш',
        ]);
        $mash = $this->driver('ПОДСЕКАЛОВ Ю.В.', 'МШ', '2101');
        $second = $this->driver('РАМЗИН В.А', 'МШ', '2102');
        NaryadAssignment::create([
            'user_id' => $mash->id,
            'plan_date' => '2026-04-10',
            'route_number' => '25 (2-ранняя)',
        ]);
        NaryadAssignment::create([
            'user_id' => $second->id,
            'plan_date' => '2026-04-10',
            'route_number' => '25 (1-с ночи)',
        ]);

        $sheet = app(NaryadPrintService::class)->build('2026-04-10');
        $rows25 = collect($sheet['rows'])->filter(
            fn ($row) => str_contains((string) $row['machinist'], 'ПОДСЕКАЛОВ')
                || str_contains((string) $row['machinist'], 'РАМЗИН')
        )->values();
        $this->assertCount(2, $rows25);
        $this->assertStringContainsString('27018', $rows25[0]['route_label']);
        $this->assertStringContainsString('25', $rows25[0]['route_label']);
        $this->assertStringContainsString('27017', $rows25[1]['route_label']);
        $this->assertStringNotContainsString('27018', $rows25[1]['route_label']);
    }

    public function test_extract_omits_consist_column(): void
    {
        $this->seedGraph();
        ArmCode::create([
            'book_code' => '03',
            'code' => '51 ш',
            'name' => '27018-27017',
            'nom' => '30',
        ]);
        ArmRoster::create([
            'roster_date' => '2026-04-10',
            'kind' => ArmRoster::KIND_PRIMARY,
            'route_number' => '25',
            'instrument' => '51 ш',
        ]);
        $mash = $this->driver('СИДОРОВ С.С.', 'МШ', '2003');
        NaryadAssignment::create([
            'user_id' => $mash->id,
            'plan_date' => '2026-04-10',
            'route_number' => '25 (1-с ночи)',
        ]);

        $sheet = app(NaryadPrintService::class)->build('2026-04-10', NaryadPrintService::KIND_EXTRACT);
        $first = collect($sheet['rows'])->first(fn ($row) => $row['shift'] === '1');
        $this->assertNotNull($first);
        $this->assertStringNotContainsString('27018', (string) $first['route_label']);
        $this->assertSame('', $first['composition']);
    }

    public function test_off_line_route_uses_kodspr_07_name(): void
    {
        $this->seedGraph();
        ArmCode::create([
            'book_code' => '07',
            'code' => 'М1',
            'name' => 'Мз-1 маневры',
            'nom' => '+',
        ]);
        ArmShiftBreakdown::create([
            'graph_code' => '01',
            'route_code' => 'М1',
            'shift_code' => '1',
            'sequence' => '1',
            'start_hours' => 5.25,
            'end_hours' => 8.05,
            'appearance_start' => 'ЗБЛ',
            'appearance_end' => 'ЗБЛ',
        ]);
        $mash = $this->driver('КРЫМСКИЙ А.С.', 'МШ', '2103');
        NaryadAssignment::create([
            'user_id' => $mash->id,
            'plan_date' => '2026-04-10',
            'route_number' => 'М1',
            'work_code' => 'М1',
            'shift_code' => '1',
        ]);

        $sheet = app(NaryadPrintService::class)->build('2026-04-10');
        $row = collect($sheet['rows'])->first(fn ($r) => str_contains((string) $r['machinist'], 'КРЫМСКИЙ'));
        $this->assertNotNull($row);
        $this->assertStringContainsString('Мз-1', $row['route_label']);
    }

    public function test_signatures_and_extra_text_go_on_full_sheet(): void
    {
        $this->seedGraph();
        ArmSignature::create([
            'sort_order' => 1,
            'position' => 'Заместитель начальника депо по эксплуатации',
            'full_name' => 'Михайлов Е.В',
        ]);
        ArmSignature::create([
            'sort_order' => 0,
            'position' => 'Председатель профкома',
            'full_name' => 'Комаров А.В.',
        ]);
        $user = User::factory()->dispatcher()->create(['name' => 'ДРОЖЖИНА НЕЛЛИ ЮРЬЕВНА']);
        $sheet = app(NaryadPrintService::class)->build(
            '2026-04-10',
            NaryadPrintService::KIND_FULL,
            $user,
            "доп. строка\n",
        );

        $this->assertCount(2, $sheet['signatures']);
        $this->assertStringContainsString('ДРОЖЖИНА', $sheet['signatures'][0]['name']);
        $this->assertStringContainsString('Михайлов', $sheet['signatures'][1]['name']);
        $this->assertStringContainsString('Заместитель', $sheet['signatures'][1]['position']);
        $this->assertFalse(collect($sheet['signatures'])->contains(
            fn ($s) => str_contains($s['name'], 'Комаров')
        ));
        $this->assertSame(['доп. строка'], $sheet['extra_text']);
    }

    private function seedGraph(): void
    {
        $type = ScheduleType::create([
            'name' => 'Рабочий',
            'foxpro_code' => '01',
            'routes_count' => 8,
            'people_per_route' => 2,
        ]);
        NaryadQuota::create([
            'plan_date' => '2026-04-10',
            'schedule_type_id' => $type->id,
            'required_crews' => 4,
        ]);
        ArmShiftBreakdown::create([
            'graph_code' => '01',
            'route_code' => '25',
            'shift_code' => '2',
            'sequence' => '1',
            'start_hours' => 5.30,
            'end_hours' => 14.20,
            'appearance_start' => 'Печат',
            'appearance_end' => 'Печат',
        ]);
        ArmShiftBreakdown::create([
            'graph_code' => '01',
            'route_code' => '25',
            'shift_code' => '1',
            'sequence' => '2',
            'start_hours' => 5.30,
            'end_hours' => 14.20,
            'appearance_start' => 'Печат',
            'appearance_end' => 'Печат',
        ]);
        ArmShiftBreakdown::create([
            'graph_code' => '01',
            'route_code' => '26',
            'shift_code' => '2',
            'sequence' => '1',
            'start_hours' => 6.00,
            'end_hours' => 15.00,
            'notes' => 'резерв',
        ]);
        ArmShiftBreakdown::create([
            'graph_code' => '01',
            'route_code' => '10',
            'shift_code' => '3+',
            'sequence' => '1',
            'start_hours' => 22.00,
            'end_hours' => 6.10,
            'appearance_start' => 'Печат',
            'appearance_end' => 'Печат',
        ]);
    }

    private function driver(string $name, string $position, string $tab): User
    {
        $user = User::factory()->driver()->create(['name' => $name]);
        ArmPersonnel::create([
            'user_id' => $user->id,
            'tab_number' => $tab,
            'full_name' => $name,
            'position_code' => $position,
        ]);

        return $user->fresh('personnel');
    }
}
