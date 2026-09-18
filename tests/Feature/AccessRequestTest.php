<?php

namespace Tests\Feature;

use App\Models\AccessRequest;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AccessRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::fake();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'tab_number' => '45231',
            'fio' => 'Иванов Иван Иванович',
            'consent' => '1',
        ], $overrides);
    }

    public function test_about_page_renders(): void
    {
        $this->get('/about')->assertOk();
    }

    public function test_valid_request_is_stored(): void
    {
        $this->from('/about')
            ->post('/about/request', $this->payload())
            ->assertRedirect('/about')
            ->assertSessionHas('success');

        $this->assertDatabaseHas('access_requests', [
            'tab_number' => '45231',
            'fio' => 'Иванов Иван Иванович',
            'status' => 'pending',
        ]);
    }

    public function test_rejects_invalid_tab_and_short_fio(): void
    {
        $this->from('/about')
            ->post('/about/request', $this->payload([
                'tab_number' => '12',
                'fio' => 'Иванов',
            ]))
            ->assertRedirect('/about')
            ->assertSessionHasErrors(['tab_number', 'fio']);

        $this->assertDatabaseCount('access_requests', 0);
    }

    public function test_rejects_without_consent(): void
    {
        $this->from('/about')
            ->post('/about/request', $this->payload(['consent' => null]))
            ->assertRedirect('/about')
            ->assertSessionHasErrors('consent');
    }

    public function test_rejects_when_tab_number_already_belongs_to_user(): void
    {
        $user = User::factory()->driver()->create();
        UserProfile::create([
            'user_id' => $user->id,
            'tab_number' => '45231',
        ]);

        $this->from('/about')
            ->post('/about/request', $this->payload())
            ->assertRedirect('/about')
            ->assertSessionHasErrors('tab_number');

        $this->assertDatabaseCount('access_requests', 0);
    }

    public function test_rejects_when_fio_matches_existing_user(): void
    {
        User::factory()->driver()->create(['name' => 'Иванов Иван Иванович']);

        $this->from('/about')
            ->post('/about/request', $this->payload(['tab_number' => '99991']))
            ->assertRedirect('/about')
            ->assertSessionHasErrors('fio');

        $this->assertDatabaseCount('access_requests', 0);
    }

    public function test_rejects_duplicate_pending_request(): void
    {
        AccessRequest::create([
            'tab_number' => '45231',
            'fio' => 'Петров Пётр',
            'status' => 'pending',
        ]);

        $this->from('/about')
            ->post('/about/request', $this->payload())
            ->assertRedirect('/about')
            ->assertSessionHasErrors('tab_number');

        $this->assertDatabaseCount('access_requests', 1);
    }

    public function test_honeypot_does_not_store_request(): void
    {
        $this->from('/about')
            ->post('/about/request', $this->payload(['website' => 'http://spam.test']))
            ->assertRedirect('/about')
            ->assertSessionHas('success');

        $this->assertDatabaseCount('access_requests', 0);
    }
}
