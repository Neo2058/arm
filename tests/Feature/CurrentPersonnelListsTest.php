<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\ArmPersonnelResource\Pages\ListArmPersonnel;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Http\Middleware\CheckDeviceBinding;
use App\Http\Middleware\CheckDynamicBarrier;
use App\Models\ArmPersonnel;
use App\Models\User;
use App\Services\ClickHouseService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class CurrentPersonnelListsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        ClickHouseService::fake();
        $this->withoutMiddleware([CheckDeviceBinding::class, CheckDynamicBarrier::class]);
    }

    private function card(string $tab, bool $active, ?string $fired = null): ArmPersonnel
    {
        $user = User::factory()->driver()->create(['is_active' => $active]);
        $user->profile()->create(['tab_number' => $tab, 'column' => '9']);

        return ArmPersonnel::create(['user_id' => $user->id, 'tab_number' => $tab, 'full_name' => 'EMPLOYEE '.$tab, 'fired_on' => $fired]);
    }

    public function test_user_admin_list_defaults_to_active_with_explicit_archive_filter(): void
    {
        $current = $this->card('1111', true);
        $archived = $this->card('2222', false);
        $this->actingAs(User::factory()->create(['role' => UserRole::ADMIN]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(ListUsers::class)->assertCanSeeTableRecords([$current->user])->assertCanNotSeeTableRecords([$archived->user])
            ->filterTable('is_active', false)->assertCanSeeTableRecords([$archived->user])->assertCanNotSeeTableRecords([$current->user])
            ->filterTable('is_active', null)->assertCanSeeTableRecords([$current->user, $archived->user]);
    }

    public function test_admin_personnel_list_requires_active_account_and_not_fired_by_default(): void
    {
        $current = $this->card('1111', true);
        $disabled = $this->card('2222', false);
        $fired = $this->card('3333', true, '2020-01-01');
        $this->actingAs(User::factory()->create(['role' => UserRole::ADMIN]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(ListArmPersonnel::class)->assertCanSeeTableRecords([$current])->assertCanNotSeeTableRecords([$disabled, $fired])
            ->filterTable('working', false)->assertCanSeeTableRecords([$current, $disabled, $fired]);
    }

    public function test_shared_and_dispatcher_lists_default_to_current_and_allow_explicit_archive_search(): void
    {
        $current = $this->card('1111', true);
        $disabled = $this->card('2222', false);
        $fired = $this->card('3333', true, '2020-01-01');
        $this->actingAs(User::factory()->dispatcher()->create());
        foreach (['/personnel', '/naryad/partial/personnel'] as $uri) {
            $this->get($uri)->assertOk()->assertSee($current->full_name)->assertDontSee($disabled->full_name)->assertDontSee($fired->full_name);
            $this->get($uri.'?active=0')->assertOk()->assertSee($current->full_name)->assertSee($disabled->full_name)->assertSee($fired->full_name);
            $this->get($uri.'?active=0&q=2222')->assertOk()->assertSee($disabled->full_name)->assertDontSee($current->full_name);
        }
    }

    public function test_archive_option_does_not_bypass_instructor_column(): void
    {
        $own = $this->card('1111', false);
        $foreign = $this->card('2222', false);
        $foreign->user->profile->update(['column' => '10']);
        $instructor = User::factory()->instructor()->create();
        $instructor->profile()->create(['column' => '9']);
        $this->actingAs($instructor)->get('/personnel?active=0')->assertOk()->assertSee($own->full_name)->assertDontSee($foreign->full_name);
    }
}
