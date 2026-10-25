<?php

namespace Tests\Feature;

use App\Enums\ChatMemberRole;
use App\Enums\ChatMemberSource;
use App\Enums\ChatType;
use App\Filament\Resources\ColumnDeputyResource;
use App\Filament\Resources\ColumnDeputyResource\Pages\CreateColumnDeputy;
use App\Filament\Resources\ColumnDeputyResource\Pages\EditColumnDeputy;
use App\Filament\Resources\ColumnDeputyResource\Pages\ListColumnDeputies;
use App\Models\ChatMember;
use App\Models\ChatRoom;
use App\Models\ColumnDeputy;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\Chat\ChatAuthorization;
use App\Services\Chat\ChatService;
use App\Services\ClickHouseService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class ColumnDeputyResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        ClickHouseService::fake();
        config([
            'chat.enabled' => true,
            'chat.pilot_column' => null,
            'chat.timezone' => 'Europe/Moscow',
        ]);
        Carbon::setTestNow(Carbon::parse('2026-10-20 12:00:00', 'Europe/Moscow'));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_admin_can_access_column_deputy_pages(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $this->assertTrue(ColumnDeputyResource::canAccess());
        $this->assertTrue(ColumnDeputyResource::shouldRegisterNavigation());
        $this->get(ColumnDeputyResource::getUrl('index'))->assertOk();
        $this->get(ColumnDeputyResource::getUrl('create'))->assertOk();
        Livewire::test(ListColumnDeputies::class)->assertSuccessful();
        Livewire::test(CreateColumnDeputy::class)->assertSuccessful();
    }

    public function test_non_admin_cannot_access_column_deputy_pages(): void
    {
        $driver = User::factory()->driver()->create();
        $this->actingAs($driver);

        $this->assertFalse(ColumnDeputyResource::canAccess());
        $this->assertFalse(ColumnDeputyResource::shouldRegisterNavigation());
        $this->get('/admin/column-deputies')->assertForbidden();
        Livewire::test(ListColumnDeputies::class)->assertForbidden();
        Livewire::test(CreateColumnDeputy::class)->assertForbidden();
    }

    public function test_create_goes_through_chat_service_and_overlap_is_422(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->driver()->create(['name' => 'Заместитель']);
        UserProfile::create(['user_id' => $user->id, 'column' => '15']);
        $room = $this->columnRoom($admin, '15');
        $this->actingAs($admin);

        Livewire::test(CreateColumnDeputy::class)
            ->fillForm([
                'user_id' => $user->id,
                'column' => '15',
                'starts_on' => '2026-10-01',
                'ends_on' => '2026-10-31',
                'reason' => 'отпуск',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(1, ColumnDeputy::query()->count());
        $this->assertDatabaseHas('chat_members', [
            'room_id' => $room->id,
            'user_id' => $user->id,
            'role' => ChatMemberRole::MODERATOR->value,
            'source' => ChatMemberSource::DEPUTY->value,
        ]);

        Livewire::test(CreateColumnDeputy::class)
            ->fillForm([
                'user_id' => $user->id,
                'column' => '15',
                'starts_on' => '2026-10-20',
                'ends_on' => '2026-10-31',
                'reason' => 'пересечение',
            ])
            ->call('create')
            ->assertHasErrors(['starts_on']);

        $this->assertSame(1, ColumnDeputy::query()->count());
        $this->assertSame(1, ChatMember::query()->where('user_id', $user->id)->count());
    }

    public function test_edit_goes_through_update_window_not_eloquent_dates(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->driver()->create();
        $room = $this->columnRoom($admin, '15');
        $period = app(ChatService::class)->grant($admin, $user, '15', '2026-10-01', '2026-10-31', 'отпуск');
        $this->actingAs($admin);

        Livewire::test(EditColumnDeputy::class, ['record' => $period->id])
            ->fillForm([
                'starts_on' => '2026-10-01',
                'ends_on' => '2026-10-19',
                'reason' => 'короче',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $member = ChatMember::query()->where('room_id', $room->id)->where('user_id', $user->id)->firstOrFail();
        $this->assertSame('2026-10-19', $member->window_ends_on->toDateString());
        $this->assertSame('короче', $period->fresh()->reason);
    }

    public function test_delete_goes_through_revoke(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->driver()->create();
        $room = $this->columnRoom($admin, '15');
        $period = app(ChatService::class)->grant($admin, $user, '15', '2026-10-01', '2026-10-31', 'отпуск');
        $this->actingAs($admin);

        Livewire::test(EditColumnDeputy::class, ['record' => $period->id])
            ->callAction('delete')
            ->assertNotified(__('filament-actions::delete.single.notifications.deleted.title'));

        $member = ChatMember::query()->where('room_id', $room->id)->where('user_id', $user->id)->firstOrFail();
        $this->assertSame('2026-10-19', $member->window_ends_on->toDateString());
        $this->assertFalse(app(ChatAuthorization::class)->isEffectiveMember($user, $room->fresh()));
        $this->assertNotNull($period->fresh());
    }

    public function test_delete_future_period_removes_the_row(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->driver()->create();
        $room = $this->columnRoom($admin, '15');
        $period = app(ChatService::class)->grant($admin, $user, '15', '2026-11-01', '2026-11-30', 'будущее');
        $this->actingAs($admin);

        Livewire::test(EditColumnDeputy::class, ['record' => $period->id])
            ->callAction('delete')
            ->assertNotified(__('filament-actions::delete.single.notifications.deleted.title'))
            ->assertRedirect(ColumnDeputyResource::getUrl('index'));

        $this->assertDatabaseMissing('column_deputies', ['id' => $period->id]);
        $this->assertFalse(app(ChatAuthorization::class)->isEffectiveMember($user, $room->fresh()));
    }

    private function columnRoom(User $creator, string $column): ChatRoom
    {
        return ChatRoom::create([
            'type' => ChatType::COLUMN,
            'title' => 'Колонна '.$column,
            'column' => $column,
            'created_by' => $creator->id,
        ]);
    }
}
