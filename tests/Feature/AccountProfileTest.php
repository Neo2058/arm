<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckDeviceBinding;
use App\Http\Middleware\CheckDynamicBarrier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AccountProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_requires_login_and_existing_device_barriers(): void
    {
        $this->get('/account')->assertRedirect('/login');
        $user = User::factory()->create();
        $this->actingAs($user)->get('/account')->assertRedirect(route('device.register.form'));
        $this->withoutMiddleware(CheckDeviceBinding::class);
        $this->get('/account')->assertRedirect(route('barrier.show'));
    }

    public function test_profile_displays_only_current_users_data_and_link_status(): void
    {
        $this->withoutMiddleware([CheckDeviceBinding::class, CheckDynamicBarrier::class]);
        $user = User::factory()->create(['name' => '<script>private</script>']);
        $user->profile()->create(['tab_number' => '0321', 'column' => '4', 'position' => 'Машинист']);
        $other = User::factory()->create(['email' => 'other-private@example.test']);
        DB::table('telegram_accounts')->insert([
            'user_id' => $other->id, 'telegram_user_id' => 99123, 'linked_at' => now(),
        ]);
        $this->actingAs($user)->get('/account?user_id='.$other->id)->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee($user->email)->assertSee('0321')->assertSee('Не привязан')
            ->assertSee('&lt;script&gt;private&lt;/script&gt;', false)
            ->assertDontSee('<script>private</script>', false)->assertDontSee($other->email);
        DB::table('telegram_accounts')->insert([
            'user_id' => $user->id, 'telegram_user_id' => 99124, 'linked_at' => now(),
        ]);
        $this->get('/account')->assertOk()->assertSee('Управлять привязкой');
    }

    public function test_missing_personnel_profile_does_not_break_account_page(): void
    {
        $this->withoutMiddleware([CheckDeviceBinding::class, CheckDynamicBarrier::class]);
        $this->actingAs(User::factory()->create())->get('/account')->assertOk()
            ->assertSee('Не указано')->assertSee('Привязать Telegram')
            ->assertSee(route('mainMenu'));
    }
}
