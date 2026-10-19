<?php

namespace Tests\Unit;

use App\Models\ActionLog;
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
}
