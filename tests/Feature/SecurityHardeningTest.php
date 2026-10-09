<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyCsrfToken;
use App\Models\User;
use Filament\Panel;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\GenerateSignedUploadUrl;
use Tests\TestCase;
use Livewire\Component;
use Livewire\Livewire;

class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function enforceCsrf(): void
    {
        $this->app->bind(VerifyCsrfToken::class, fn ($app) => new class($app, $app->make(Encrypter::class)) extends VerifyCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
    }

    public function test_livewire_header_does_not_bypass_csrf_on_login(): void
    {
        $this->enforceCsrf();

        $this->withHeader('X-Livewire', 'true')->post('/login', [
            'email' => 'test@example.com', 'password' => 'password',
        ])->assertStatus(419);
    }

    public function test_filament_logout_does_not_accept_livewire_header_as_csrf(): void
    {
        $this->enforceCsrf();
        $this->actingAs(User::factory()->admin()->create())
            ->withHeader('X-Livewire', 'true')->post('/admin/logout')->assertStatus(419);
    }

    public function test_active_user_can_login_with_matching_csrf_token(): void
    {
        $user = User::factory()->create();
        $this->enforceCsrf();

        $this->withSession(['_token' => 'security-test-token'])->post('/login', [
            '_token' => 'security-test-token',
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect();
        $this->assertAuthenticatedAs($user);
    }

    public function test_signed_livewire_upload_accepts_matching_csrf_token(): void
    {
        Storage::fake('local');
        Storage::fake('tmp-for-tests');
        config(['livewire.temporary_file_upload.disk' => 'local']);
        $this->enforceCsrf();
        $url = app(GenerateSignedUploadUrl::class)->forLocal();

        $this->withSession(['_token' => 'security-test-token'])
            ->withHeader('X-CSRF-TOKEN', 'security-test-token')
            ->post($url, ['files' => [UploadedFile::fake()->create('instruction.pdf', 10, 'application/pdf')]])
            ->assertOk()->assertJsonCount(1, 'paths');
    }

    public function test_livewire_update_and_upload_require_csrf(): void
    {
        $this->enforceCsrf();

        foreach (['/livewire/update', '/livewire/upload-file'] as $uri) {
            $this->withHeader('X-Livewire', 'true')->post($uri)->assertStatus(419);
        }
    }

    public function test_livewire_update_accepts_matching_session_csrf_token(): void
    {
        Livewire::component('security-csrf-probe', SecurityCsrfProbe::class);
        $snapshot = Livewire::test('security-csrf-probe')->snapshot;
        $this->enforceCsrf();

        $this->withSession(['_token' => 'security-test-token'])
            ->withHeader('X-CSRF-TOKEN', 'security-test-token')
            ->withHeader('X-Livewire', 'true')
            ->postJson('/livewire/update', ['components' => [[
                'snapshot' => json_encode($snapshot), 'updates' => [], 'calls' => [],
            ]]])->assertOk();
    }

    public function test_inactive_web_user_cannot_login(): void
    {
        $user = User::factory()->create(['is_active' => false]);

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_deactivation_revokes_tokens_and_reactivation_does_not_restore_them(): void
    {
        $user = User::factory()->create();
        $user->createToken('mobile');

        $user->update(['is_active' => false]);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $user->update(['is_active' => true]);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_api_checks_activity_even_when_database_update_bypasses_model_events(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('mobile')->plainTextToken;
        DB::table('users')->where('id', $user->id)->update(['is_active' => false]);

        $this->withToken($token)->getJson('/api/mobile/documents')->assertForbidden();
    }

    public function test_revoked_bearer_token_cannot_access_api(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('mobile')->plainTextToken;
        $user->update(['is_active' => false]);

        $this->withToken($token)->getJson('/api/mobile/me')->assertUnauthorized();
    }

    public function test_inactive_session_cannot_update_livewire(): void
    {
        $user = User::factory()->admin()->create();
        $this->actingAs($user);
        DB::table('users')->where('id', $user->id)->update(['is_active' => false]);

        $this->postJson('/livewire/update', ['components' => []])->assertForbidden();
        $this->assertGuest();
    }

    public function test_inactive_administrator_cannot_access_panel(): void
    {
        $user = User::factory()->admin()->create(['is_active' => false]);

        $this->assertFalse($user->canAccessPanel($this->createStub(Panel::class)));
        $this->actingAs($user)->get('/admin')->assertForbidden();
    }

    public function test_login_attempts_are_limited_per_account_across_ips(): void
    {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.$attempt])
                ->postJson('/api/mobile/login', [
                    'email' => 'limited@example.com', 'password' => 'invalid',
                ])->assertStatus(422);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.99'])
            ->postJson('/api/mobile/login', [
                'email' => 'LIMITED@example.com', 'password' => 'invalid',
            ])->assertStatus(429)->assertHeader('Retry-After');
    }

    public function test_web_and_mobile_login_share_the_account_limit(): void
    {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->post('/login', ['email' => 'shared@example.com', 'password' => 'invalid'])
                ->assertRedirect();
        }

        $this->postJson('/api/mobile/login', ['email' => 'shared@example.com', 'password' => 'invalid'])
            ->assertStatus(429);
    }

    public function test_barrier_attempts_are_limited_per_user(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->postJson('/api/barrier/verify', ['answers' => [9, 9, 9, 9]])
                ->assertUnprocessable();
        }

        $this->postJson('/api/barrier/verify', ['answers' => [9, 9, 9, 9]])
            ->assertStatus(429)->assertHeader('Retry-After');
    }
}

class SecurityCsrfProbe extends Component
{
    public function render()
    {
        return '<div>CSRF probe</div>';
    }
}
