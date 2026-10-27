<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Http\Middleware\CheckDeviceBinding;
use App\Http\Middleware\CheckDynamicBarrier;
use App\Models\ArmPersonnel;
use App\Models\User;
use App\Services\ClickHouseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SelfProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        ClickHouseService::fake();
        $this->withoutMiddleware([CheckDeviceBinding::class, CheckDynamicBarrier::class]);
    }

    private function employee(): User
    {
        $user = User::factory()->driver()->create();
        $user->profile()->create(['tab_number' => '1234', 'column' => '9', 'instructor' => 'Instructor']);
        ArmPersonnel::create(['user_id' => $user->id, 'tab_number' => '1234', 'full_name' => 'OLD',
            'source_data' => ['TABNOM' => '1234', 'FIO' => 'OLD'], 'class_code' => '1']);

        return $user;
    }

    public function test_web_employee_edits_own_fields_and_changes_are_shared_with_directory(): void
    {
        $user = $this->employee();
        $this->actingAs($user)->get('/account')->assertOk()->assertSee('Сохранить профиль');
        $this->patch('/account', ['full_name' => 'Новое полное имя сотрудника длиннее сорока символов',
            'phone_primary' => '+79991234567', 'phone_secondary' => '123-45-67', 'class_code' => '2',
            'birth_date' => '1990-01-01', 'driverRoot' => 'ABC123', 'seniority_on' => '2001-01-01',
            'med_from' => '2026-01-01', 'med_to' => '2026-12-31',
            'early_windows' => [['time' => '07:15', 'reason' => 'ДП']],
        ])->assertRedirect(route('account.show'));
        $user->refresh()->load(['profile', 'personnel']);
        $this->assertSame($user->name, $user->personnel->full_name);
        $this->assertSame('+79991234567', $user->personnel->phone_primary);
        $this->assertSame('+79991234567', $user->profile->phoneNumber);
        $this->assertSame('2', $user->profile->normative_class);
        $this->assertSame('ABC123', $user->profile->driverRoot);
        $this->assertSame('9', $user->profile->column);
        $this->assertSame('OLD', $user->personnel->source_data['FIO']);
        $this->assertSame([['time' => '07.15', 'reason' => 'ДП']], $user->personnel->early_windows);
        $this->get('/personnel')->assertForbidden();
    }

    public function test_mobile_token_profile_reads_and_updates_same_card(): void
    {
        $user = $this->employee();
        $token = $user->createToken('mobile')->plainTextToken;
        $this->withToken($token)->getJson('/api/mobile/account/profile')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('readonly.Колонна', '9');
        $this->withToken($token)->patchJson('/api/mobile/account/profile', [
            'phone_primary' => '555-55-55', 'assistant_seniority_years' => 4,
            'late_windows' => [['time' => '22.00', 'reason' => '+']],
        ])->assertOk()->assertJsonPath('values.phone_primary', '555-55-55')->assertJsonPath('values.assistant_seniority_years', 4);
        $this->assertSame('555-55-55', $user->profile->fresh()->phoneNumber);
        $this->assertSame([['time' => '22.00', 'reason' => '+']], $user->personnel->fresh()->late_windows);
    }

    public function test_privilege_identity_and_other_account_changes_are_rejected_even_for_admin_self_service(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $user = $this->employee();
        foreach ([UserRole::DRIVER, UserRole::ADMIN] as $role) {
            $user->update(['role' => $role]);
            $this->actingAs($user);
            foreach (['role' => 'super_admin', 'is_active' => false, 'column' => '10', 'instructor' => 'Other',
                'tab_number' => '9999', 'email' => 'other@mail.ru', 'user_id' => 999, 'fired_on' => '2001-01-01',
                'source_data' => ['FIO' => 'NEW'], 'password' => 'new-password'] as $key => $value) {
                $this->patchJson('/account', [$key => $value])->assertUnprocessable();
                $this->patchJson('/account', [$key => null])->assertUnprocessable();
            }
        }
        $this->assertSame('9', $user->profile->fresh()->column);
        $this->assertSame('1234', $user->profile->fresh()->tab_number);
    }

    public function test_invalid_submission_rolls_back_all_profile_and_card_fields(): void
    {
        $user = $this->employee();
        $name = $user->name;
        $this->actingAs($user)->patchJson('/account', ['full_name' => 'Changed', 'birth_date' => '1990-01-01',
            'med_from' => '2026-12-31', 'med_to' => '2026-01-01'])->assertUnprocessable();
        $this->assertSame($name, $user->fresh()->name);
        $this->assertNull($user->profile->fresh()->birth_date);
        $this->assertNull($user->personnel->fresh()->med_from);
    }

    public function test_guest_inactive_user_and_unrelated_ids_have_no_access(): void
    {
        $this->getJson('/api/mobile/account/profile')->assertUnauthorized();
        $this->patch('/account', [])->assertRedirect(route('login'));
        $user = $this->employee();
        $other = User::factory()->driver()->create();
        $other->profile()->create(['tab_number' => '9999']);
        $token = $user->createToken('mobile')->plainTextToken;
        $this->withToken($token)->patchJson('/api/mobile/account/profile', ['user_id' => $other->id, 'full_name' => 'ATTACK'])->assertUnprocessable();
        $this->assertNotSame('ATTACK', $other->fresh()->name);
        $user->update(['is_active' => false]);
        $this->withToken($token)->getJson('/api/mobile/account/profile')->assertForbidden();
    }

    public function test_user_without_personnel_card_can_edit_basic_profile_without_creating_card(): void
    {
        $user = User::factory()->create(['role' => UserRole::ADMIN]);
        $this->actingAs($user)->patch('/account', ['full_name' => 'Admin Name', 'phone_primary' => '123', 'birth_date' => '1980-01-01'])->assertRedirect();
        $this->assertSame('Admin Name', $user->fresh()->name);
        $this->assertSame('123', $user->profile()->first()->phoneNumber);
        $this->assertDatabaseCount('arm_personnel', 0);
        $this->patchJson('/account', ['med_from' => '2026-01-01'])->assertUnprocessable();
    }
}
