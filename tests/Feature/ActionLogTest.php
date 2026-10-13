<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckDeviceBinding;
use App\Http\Middleware\CheckDynamicBarrier;
use App\Models\ActionLog;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\ClickHouseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\CallQueuedClosure;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ActionLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->withoutMiddleware([
            CheckDeviceBinding::class,
            CheckDynamicBarrier::class,
        ]);
    }

    public function test_payload_uses_explicit_user_and_profile_column(): void
    {
        $user = User::factory()->driver()->create();
        UserProfile::create([
            'user_id' => $user->id,
            'column' => '15',
        ]);

        $row = ClickHouseService::payload('login', 0, '', $user->fresh('profile'));

        $this->assertSame($user->id, $row['user_id']);
        $this->assertSame('driver', $row['user_role']);
        $this->assertSame('15', $row['user_column']);
    }

    public function test_action_log_forwards_user_id_and_document_id_once(): void
    {
        $user = User::factory()->driver()->create();
        $this->actingAs($user);

        ActionLog::log('view_document', [
            'document_id' => 9,
            'title' => 'Инструкция',
        ], $user->id);

        $this->assertDatabaseHas('action_logs', [
            'user_id' => $user->id,
            'action' => 'view_document',
        ]);
        $this->assertSame(9, ActionLog::resourceIdFromDetails(['document_id' => 9, 'title' => 'x']));
        Queue::assertPushedOn('analytics', CallQueuedClosure::class);
        Queue::assertPushed(CallQueuedClosure::class, 1);
    }

    public function test_client_can_log_allowlisted_ui_action(): void
    {
        $user = User::factory()->driver()->create();

        $this->actingAs($user)
            ->postJson('/api/actions/log', [
                'action' => 'open_naryad',
                'details' => ['id' => 3, 'title' => 'Наряд', 'nested' => ['drop' => true]],
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('action_logs', [
            'user_id' => $user->id,
            'action' => 'ui.open_naryad',
        ]);

        $log = ActionLog::query()->first();
        $this->assertSame(3, $log->details['id']);
        $this->assertSame('Наряд', $log->details['title']);
        $this->assertArrayNotHasKey('nested', $log->details);
    }

    public function test_client_cannot_spoof_server_action(): void
    {
        $user = User::factory()->driver()->create();

        $this->actingAs($user)
            ->postJson('/api/actions/log', [
                'action' => 'complete_quiz',
                'details' => ['score' => 100],
            ])
            ->assertUnprocessable();

        $this->assertDatabaseMissing('action_logs', [
            'action' => 'complete_quiz',
        ]);
        $this->assertDatabaseMissing('action_logs', [
            'action' => 'ui.complete_quiz',
        ]);
    }

    public function test_guest_cannot_post_client_log(): void
    {
        $this->postJson('/api/actions/log', [
            'action' => 'open_naryad',
        ])->assertUnauthorized();
    }
}
