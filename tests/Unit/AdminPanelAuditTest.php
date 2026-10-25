<?php

namespace Tests\Unit;

use App\Enums\ChatType;
use App\Models\ActionLog;
use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\ColumnDeputy;
use App\Models\User;
use App\Services\AdminPanelAudit;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPanelAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_model_create_outside_filament_is_not_audited(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        User::factory()->driver()->create();

        $this->assertFalse(AdminPanelAudit::isAdminPanelRequest());
        $this->assertDatabaseMissing('action_logs', [
            'action' => 'admin.user.created',
        ]);
    }

    public function test_filament_serving_admin_records_crud_without_password_values(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setServingStatus();

        $this->assertTrue(AdminPanelAudit::isAdminPanelRequest());

        $user = User::factory()->driver()->create(['name' => 'Иванов']);

        $this->assertDatabaseHas('action_logs', [
            'user_id' => $admin->id,
            'action' => 'admin.user.created',
        ]);
        $created = ActionLog::query()->where('action', 'admin.user.created')->first();
        $this->assertSame($user->id, $created->details['id']);
        $this->assertSame('Иванов', $created->details['name']);
        $this->assertSame($user->email, $created->details['email']);
        $this->assertArrayNotHasKey('password', $created->details);

        $user->update(['name' => 'Петров', 'password' => 'new-secret']);

        $updated = ActionLog::query()->where('action', 'admin.user.updated')->first();
        $this->assertNotNull($updated);
        $this->assertContains('name', $updated->details['changed']);
        $this->assertContains('password', $updated->details['changed']);
        $this->assertSame('Петров', $updated->details['name']);
        $this->assertStringNotContainsString('new-secret', json_encode($updated->details));

        $id = $user->id;
        $user->delete();

        $this->assertDatabaseHas('action_logs', [
            'action' => 'admin.user.deleted',
        ]);
        $deleted = ActionLog::query()->where('action', 'admin.user.deleted')->first();
        $this->assertSame($id, $deleted->details['id']);
    }

    public function test_touch_only_updated_at_is_not_logged(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setServingStatus();

        $user = User::factory()->driver()->create();
        ActionLog::query()->delete();

        $user->touch();

        $this->assertDatabaseMissing('action_logs', [
            'action' => 'admin.user.updated',
        ]);
    }

    public function test_driver_cannot_emit_admin_audit_even_when_serving(): void
    {
        $this->actingAs(User::factory()->driver()->create());
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setServingStatus();

        $this->assertFalse(AdminPanelAudit::isAdminPanelRequest());
        User::factory()->driver()->create();

        $this->assertDatabaseMissing('action_logs', [
            'action' => 'admin.user.created',
        ]);
    }

    public function test_chat_room_and_column_deputy_are_audited_without_bodies(): void
    {
        $this->assertContains(ChatRoom::class, AdminPanelAudit::MODELS);
        $this->assertContains(ColumnDeputy::class, AdminPanelAudit::MODELS);
        $this->assertNotContains(ChatMessage::class, AdminPanelAudit::MODELS);

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setServingStatus();

        $room = ChatRoom::create([
            'type' => ChatType::COLUMN,
            'title' => 'Колонна 15',
            'column' => '15',
            'created_by' => $admin->id,
        ]);
        $created = ActionLog::query()->where('action', 'admin.chat_room.created')->first();
        $this->assertNotNull($created);
        $this->assertSame($room->id, $created->details['id']);
        $this->assertSame('Колонна 15', $created->details['title']);
        $this->assertArrayNotHasKey('body', $created->details);
        $this->assertArrayNotHasKey('body_ciphertext', $created->details);

        $deputy = ColumnDeputy::create([
            'column' => '15',
            'user_id' => User::factory()->driver()->create()->id,
            'granted_by' => $admin->id,
            'starts_on' => '2026-10-01',
            'ends_on' => '2026-10-31',
            'reason' => 'отпуск',
        ]);
        $deputyLog = ActionLog::query()->where('action', 'admin.column_deputy.created')->first();
        $this->assertNotNull($deputyLog);
        $this->assertSame($deputy->id, $deputyLog->details['id']);
        $this->assertArrayNotHasKey('body', $deputyLog->details);
        $this->assertSame(0, ActionLog::query()->where('action', 'like', 'chat.member.%')->count());
        $this->assertSame(0, ActionLog::query()->where('action', 'chat.message.deleted')->count());
    }
}
