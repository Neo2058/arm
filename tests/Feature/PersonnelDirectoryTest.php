<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\ArmPersonnelResource;
use App\Filament\Resources\ArmPersonnelResource\Pages\ListArmPersonnel;
use App\Http\Middleware\CheckDeviceBinding;
use App\Http\Middleware\CheckDynamicBarrier;
use App\Models\ArmPersonnel;
use App\Models\User;
use App\Services\Arm\PersonnelDirectory;
use App\Services\Arm\PersonnelImporter;
use App\Services\ClickHouseService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\DbfWriter;
use Tests\TestCase;

class PersonnelDirectoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        ClickHouseService::fake();
        $this->withoutMiddleware([CheckDeviceBinding::class, CheckDynamicBarrier::class]);
    }

    private function person(string $tab, ?string $column): ArmPersonnel
    {
        $user = User::factory()->driver()->create();
        $user->profile()->create(['tab_number' => $tab, 'column' => $column]);

        return ArmPersonnel::create(['user_id' => $user->id, 'tab_number' => $tab, 'full_name' => 'СОТРУДНИК '.$tab]);
    }

    private function instructor(?string $column): User
    {
        $user = User::factory()->instructor()->create();
        $user->profile()->create(['column' => $column]);

        return $user;
    }

    public function test_instructor_only_sees_own_column_in_lists_search_and_direct_links(): void
    {
        $own = $this->person('1111', '9');
        $other = $this->person('2222', '10');
        $unassigned = $this->person('3333', null);
        $actor = $this->instructor('9');
        $this->actingAs($actor)->get('/personnel')->assertOk()->assertSee($own->full_name)
            ->assertDontSee($other->full_name)->assertDontSee($unassigned->full_name);
        $this->get('/personnel?q=2222&column=10')->assertOk()->assertDontSee($other->full_name);
        $this->get('/personnel/'.$own->id)->assertOk();
        $this->get('/personnel/'.$other->id)->assertNotFound();
        $this->get('/personnel/'.$unassigned->id)->assertNotFound();
        $this->patch('/personnel/'.$other->id, ['phone_primary' => '123'])->assertNotFound();
        $this->assertNull($other->refresh()->phone_primary);
    }

    public function test_instructor_without_column_sees_nothing(): void
    {
        $person = $this->person('1111', null);
        foreach ([null, '', '   '] as $column) {
            $actor = $this->instructor($column);
            $this->actingAs($actor)->get('/personnel')->assertOk()->assertDontSee($person->full_name);
            $this->get('/personnel/'.$person->id)->assertNotFound();
        }
    }

    public function test_instructor_updates_own_card_and_shared_profile_atomically(): void
    {
        $person = $this->person('1111', '9');
        $actor = $this->instructor('9');
        $this->actingAs($actor)->patch('/personnel/'.$person->id, [
            'phone_primary' => '123456', 'class_code' => '2', 'is_brigadier' => true,
        ])->assertRedirect(route('personnel.show', $person));
        $this->assertSame('123456', $person->refresh()->phone_primary);
        $this->assertSame('123456', $person->user->profile->phoneNumber);
        $this->assertSame('2', $person->user->profile->normative_class);
        $this->assertTrue($person->user->profile->is_brigadir);
        $this->assertSame('9', $person->user->profile->column);
    }

    public function test_instructor_cannot_reassign_column_or_identity_or_submit_invalid_dates(): void
    {
        $person = $this->person('1111', '9');
        $this->actingAs($this->instructor('9'));
        foreach ([['column' => '10'], ['tab_number' => '2222'], ['user_id' => 1], ['fired_on' => '2020-01-01']] as $data) {
            $this->patchJson('/personnel/'.$person->id, $data)->assertUnprocessable();
        }
        $this->patchJson('/personnel/'.$person->id, ['med_from' => '2026-10-20', 'med_to' => '2026-10-10'])
            ->assertUnprocessable();
        $this->assertNull($person->refresh()->med_from);
    }

    public function test_all_extended_fields_are_editable_without_losing_source_snapshot(): void
    {
        $person = $this->person('1111', '9');
        $person->update(['source_data' => ['OTBOR' => '+', 'TABNOM' => '1111', 'TEL2' => 'old']]);
        $this->actingAs($this->instructor('9'))->patch('/personnel/'.$person->id, [
            'phone_secondary' => '123-45-67', 'seniority_on' => '2001-01-01',
            'assistant_seniority_on' => '1998-01-01', 'assistant_seniority_years' => 3,
            'early_windows' => [['time' => '07:15', 'reason' => 'ДП'], ['time' => '', 'reason' => '']],
            'late_windows' => [['time' => '22.00', 'reason' => '+']],
            'brigadier_from' => '2020-01-01', 'brigadier_to' => '2025-01-01',
            'shop_code' => '03а', 'roster_number' => '25', 'category_code' => '1',
            'premium_flag' => '+', 'main_tab_number' => '1234', 'depo_code' => 'ТЧ15',
        ])->assertRedirect();
        $person->refresh();
        $this->assertSame([['time' => '07.15', 'reason' => 'ДП']], $person->early_windows);
        $this->assertSame([['time' => '22.00', 'reason' => '+']], $person->late_windows);
        $this->assertSame('123-45-67', $person->phone_secondary);
        $this->assertSame('2001-01-01', $person->seniority_on->toDateString());
        $this->assertSame(3, $person->assistant_seniority_years);
        $this->assertSame('03а', $person->shop_code);
        $this->assertSame('+', $person->source_data['OTBOR']);
        $this->get('/personnel/'.$person->id)->assertOk()->assertSee('07.15')->assertSee('22.00')->assertSee('OTBOR');
        $this->patchJson('/personnel/'.$person->id, ['source_data' => ['OTBOR' => '-']])->assertUnprocessable();
        $this->patchJson('/personnel/'.$person->id, ['early_windows' => [['time' => '25.99']]])->assertUnprocessable();
    }

    public function test_admin_can_assign_and_change_column_and_access_changes_immediately(): void
    {
        $person = $this->person('1111', null);
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $instructor = $this->instructor('9');
        $this->actingAs($admin)->patch('/personnel/'.$person->id, ['column' => '9'])->assertRedirect();
        $this->actingAs($instructor)->get('/personnel/'.$person->id)->assertOk();
        $this->actingAs($admin)->patch('/personnel/'.$person->id, ['column' => '10'])->assertRedirect();
        $this->actingAs($instructor)->get('/personnel/'.$person->id)->assertNotFound();
        $this->patch('/personnel/'.$person->id, ['phone_primary' => '123'])->assertNotFound();
    }

    public function test_dispatcher_has_full_directory_and_operator_has_read_access(): void
    {
        $person = $this->person('1111', null);
        $other = $this->person('2222', '10');
        $dispatcher = User::factory()->dispatcher()->create();
        $this->actingAs($dispatcher)->get('/personnel')->assertOk()->assertSee($person->full_name)->assertSee($other->full_name);
        $this->get('/naryad/partial/personnel')->assertOk()->assertSee(route('personnel.show', $person), false);
        $this->patch('/personnel/'.$person->id, ['phone_primary' => '123'])->assertRedirect();
        $this->patchJson('/personnel/'.$person->id, ['column' => '9'])->assertUnprocessable();
        $operator = User::factory()->create(['role' => UserRole::OPERATOR]);
        $this->actingAs($operator)->get('/personnel/'.$person->id)->assertOk();
        $this->patch('/personnel/'.$person->id, ['phone_primary' => '456'])->assertForbidden();
    }

    public function test_guests_drivers_students_inactive_users_cannot_access_directory(): void
    {
        $person = $this->person('1111', '9');
        $this->get('/personnel')->assertRedirect(route('login'));
        foreach ([UserRole::DRIVER, UserRole::STUDENT, UserRole::NACHALNIK, UserRole::TORMOZNIK] as $role) {
            $actor = User::factory()->create(['role' => $role]);
            $this->actingAs($actor)->get('/personnel')->assertForbidden();
            $this->get('/personnel/'.$person->id)->assertForbidden();
            $this->patch('/personnel/'.$person->id, ['full_name' => 'ЧУЖОЙ'])->assertForbidden();
            $this->assertSame(0, app(PersonnelDirectory::class)->query($actor)->count());
        }
        $actor = User::factory()->instructor()->create(['is_active' => false]);
        $this->assertFalse(app(PersonnelDirectory::class)->canAccess($actor));
        $this->assertSame(0, app(PersonnelDirectory::class)->query($actor)->count());
    }

    public function test_admin_menu_resource_renders_and_edits_through_shared_service(): void
    {
        $person = $this->person('1111', null);
        $person->update(['source_data' => ['OTBOR' => '+', 'VISLP' => 0, 'DTUV' => null]]);
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->assertTrue(ArmPersonnelResource::canViewAny());
        $this->assertSame('Картотека машинистов', ArmPersonnelResource::getNavigationLabel());
        Livewire::test(ListArmPersonnel::class)->assertCanSeeTableRecords([$person])
            ->callTableAction('edit', $person, data: ['column' => '9', 'phone_primary' => '123456'])
            ->assertHasNoTableActionErrors();
        $this->assertSame('9', $person->user->profile->fresh()->column);
        $this->assertSame('123456', $person->refresh()->phone_primary);
        $this->actingAs(User::factory()->dispatcher()->create());
        $this->assertFalse(ArmPersonnelResource::canViewAny());
    }

    public function test_admin_can_assign_column_to_multiple_cards(): void
    {
        $person = $this->person('1111', null);
        $other = $this->person('2222', null);
        $this->actingAs(User::factory()->create(['role' => UserRole::ADMIN]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(ListArmPersonnel::class)->callTableBulkAction('assignColumn', [$person, $other], data: ['column' => '10'])
            ->assertHasNoTableBulkActionErrors();
        $this->assertSame('10', $person->user->profile->fresh()->column);
        $this->assertSame('10', $other->user->profile->fresh()->column);
    }

    public function test_import_reuses_real_account_and_preserves_full_name_column_role_and_password(): void
    {
        $actor = $this->instructor('9');
        $actor->profile->update(['tab_number' => '1111', 'instructor' => 'Existing']);
        $hash = $actor->password;
        $name = $actor->name;
        $dir = sys_get_temp_dir().'/personnel_'.uniqid();
        mkdir($dir);
        try {
            DbfWriter::write($dir.'/LKM.DBF', [
                ['name' => 'TABNOM', 'type' => 'C', 'length' => 4],
                ['name' => 'FIO', 'type' => 'C', 'length' => 18],
            ], [['TABNOM' => '1111', 'FIO' => 'ИВАНОВ И.И']]);
            $before = User::count();
            $importer = app(PersonnelImporter::class);
            $this->assertSame(1, $importer->importPersonnel($dir.'/LKM.DBF'));
            $this->assertSame(1, $importer->importPersonnel($dir.'/LKM.DBF'));
            $this->assertSame($before, User::count());
            $this->assertSame($actor->id, ArmPersonnel::sole()->user_id);
            $this->assertSame($hash, $actor->fresh()->password);
            $this->assertSame($name, $actor->fresh()->name);
            $this->assertSame('9', $actor->profile->fresh()->column);
            $this->assertSame('Existing', $actor->profile->fresh()->instructor);
            $this->assertTrue($actor->fresh()->isInstructor());
        } finally {
            unlink($dir.'/LKM.DBF');
            rmdir($dir);
        }
    }

    public function test_import_conflict_rolls_back_entire_batch(): void
    {
        $existing = $this->person('2222', '9');
        User::factory()->driver()->create(['email' => 'tab2222@arm.local']);
        $dir = sys_get_temp_dir().'/personnel_conflict_'.uniqid();
        mkdir($dir);
        try {
            DbfWriter::write($dir.'/LKM.DBF', [
                ['name' => 'TABNOM', 'type' => 'C', 'length' => 4],
                ['name' => 'FIO', 'type' => 'C', 'length' => 18],
            ], [['TABNOM' => '1111', 'FIO' => 'NEW'], ['TABNOM' => '2222', 'FIO' => 'CONFLICT']]);
            try {
                app(PersonnelImporter::class)->importPersonnel($dir.'/LKM.DBF');
                $this->fail('Expected account conflict');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('Конфликт аккаунтов', $e->getMessage());
            }
            $this->assertDatabaseMissing('arm_personnel', ['tab_number' => '1111']);
            $this->assertDatabaseMissing('users', ['email' => 'tab1111@arm.local']);
            $this->assertSame($existing->full_name, $existing->fresh()->full_name);
        } finally {
            unlink($dir.'/LKM.DBF');
            rmdir($dir);
        }
    }
}
