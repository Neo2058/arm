<?php

namespace Tests\Feature;

use App\Enums\ChatMemberRole;
use App\Enums\ChatMemberSource;
use App\Enums\ChatType;
use App\Models\ChatMember;
use App\Models\ChatRoom;
use App\Models\ColumnDeputy;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\Chat\ChatAuthorization;
use App\Services\Chat\ChatEnrollment;
use App\Services\Chat\ChatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ChatAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'chat.enabled' => true,
            'chat.pilot_column' => null,
            'chat.timezone' => 'Europe/Moscow',
        ]);
        Carbon::setTestNow(Carbon::parse('2026-10-20 12:00:00', 'Europe/Moscow'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_deputy_before_starts_on_is_not_a_member(): void
    {
        [$authz, $deputy, $room] = $this->grantedDeputy('2026-10-25', '2026-10-31');

        $this->assertFalse($authz->isEffectiveMember($deputy, $room));
        $this->assertFalse($authz->canManageMembers($deputy, $room));
        $this->assertFalse($authz->roomsFor($deputy)->contains('id', $room->id));
    }

    public function test_deputy_inside_window_is_moderator_and_visible_in_empty_room(): void
    {
        [$authz, $deputy, $room] = $this->grantedDeputy('2026-10-01', '2026-10-31');

        $this->assertTrue($authz->isEffectiveMember($deputy, $room));
        $this->assertTrue($authz->canManageMembers($deputy, $room));
        $this->assertTrue($authz->roomsFor($deputy)->contains('id', $room->id));
        $this->assertSame(0, $room->messages()->count());
        $this->assertDatabaseHas('chat_members', [
            'room_id' => $room->id,
            'user_id' => $deputy->id,
            'role' => ChatMemberRole::MODERATOR->value,
            'source' => ChatMemberSource::DEPUTY->value,
        ]);
    }

    public function test_deputy_after_ends_on_is_not_a_member(): void
    {
        [$authz, $deputy, $room] = $this->grantedDeputy('2026-10-01', '2026-10-10');

        $this->assertFalse($authz->isEffectiveMember($deputy, $room));
        $this->assertFalse($authz->roomsFor($deputy)->contains('id', $room->id));
    }

    public function test_moscow_midnight_closes_window_that_ended_on_the_24th(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-24 21:00:00', 'UTC'));
        $this->assertSame(
            '2026-10-25 00:00:00',
            now()->timezone('Europe/Moscow')->format('Y-m-d H:i:s'),
        );

        [$authz, $deputy, $room] = $this->grantedDeputy('2026-10-01', '2026-10-24');

        $this->assertFalse($authz->isEffectiveMember($deputy, $room));
        $this->assertFalse($authz->roomsFor($deputy)->contains('id', $room->id));
        $this->assertFalse($authz->deputyWindowActive('2026-10-01', '2026-10-24'));
    }

    public function test_update_window_shortens_ends_on_and_drops_membership_immediately(): void
    {
        [$authz, $deputy, $room, $period] = $this->grantedDeputy('2026-10-01', '2026-10-31');
        $this->assertTrue($authz->isEffectiveMember($deputy, $room));

        app(ChatService::class)->updateWindow($period, '2026-10-01', '2026-10-19');

        $this->assertFalse($authz->isEffectiveMember($deputy, $room->fresh()));
        $this->assertFalse($authz->roomsFor($deputy)->contains('id', $room->id));
    }

    public function test_revoke_deputy_only_removes_from_rooms_for(): void
    {
        [$authz, $deputy, $room, $period] = $this->grantedDeputy('2026-10-01', '2026-10-31');
        app(ChatService::class)->revoke($period);

        $this->assertFalse($authz->isEffectiveMember($deputy, $room->fresh()));
        $this->assertFalse($authz->roomsFor($deputy)->contains('id', $room->id));
    }

    public function test_revoke_keeps_regular_member_but_drops_manage_overlay(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->driver()->create();
        $room = $this->columnRoom('15', $admin);
        ChatMember::create([
            'room_id' => $room->id,
            'user_id' => $user->id,
            'role' => ChatMemberRole::MEMBER,
            'source' => ChatMemberSource::REGULAR,
            'added_by' => $admin->id,
        ]);

        $period = app(ChatService::class)->grant($admin, $user, '15', '2026-10-01', '2026-10-31', 'отпуск');
        $member = ChatMember::query()->where('room_id', $room->id)->where('user_id', $user->id)->firstOrFail();
        $this->assertSame(ChatMemberSource::REGULAR, $member->source);
        $this->assertNotNull($member->window_starts_on);

        $authz = app(ChatAuthorization::class);
        $this->assertTrue($authz->isEffectiveMember($user, $room));
        $this->assertTrue($authz->canManageMembers($user, $room));

        app(ChatService::class)->revoke($period);

        $member = $member->fresh();
        $this->assertNotNull($member);
        $this->assertSame(ChatMemberSource::REGULAR, $member->source);
        $this->assertNull($member->window_starts_on);
        $this->assertNull($member->window_ends_on);
        $this->assertTrue($authz->isEffectiveMember($user, $room->fresh()));
        $this->assertTrue($authz->roomsFor($user)->contains('id', $room->id));
        $this->assertFalse($authz->canManageMembers($user, $room->fresh()));
    }

    public function test_assert_mobile_room_disabled_is_404(): void
    {
        config(['chat.enabled' => false]);
        [$authz, $deputy, $room] = $this->grantedDeputy('2026-10-01', '2026-10-31');

        $this->assertHttp($authz, $deputy, $room, 404);
    }

    public function test_assert_mobile_room_other_pilot_column_is_404(): void
    {
        config(['chat.pilot_column' => '15']);
        [$authz, $deputy, $room] = $this->grantedDeputy('2026-10-01', '2026-10-31', '99');

        $this->assertTrue($authz->isEffectiveMember($deputy, $room));
        $this->assertFalse($authz->roomsFor($deputy)->contains('id', $room->id));
        $this->assertHttp($authz, $deputy, $room, 404);
    }

    public function test_assert_mobile_room_archived_is_410(): void
    {
        [$authz, $deputy, $room] = $this->grantedDeputy('2026-10-01', '2026-10-31');
        $room->archived_at = now();
        $room->save();

        $this->assertFalse($authz->isEffectiveMember($deputy, $room->fresh()));
        $this->assertHttp($authz, $deputy, $room->fresh(), 410);
    }

    public function test_assert_mobile_room_non_member_is_403(): void
    {
        $admin = User::factory()->admin()->create();
        $room = $this->columnRoom('15', $admin);
        $stranger = User::factory()->driver()->create();

        $this->assertHttp(app(ChatAuthorization::class), $stranger, $room, 403);
    }

    public function test_enrollment_remember_sets_timestamp_once(): void
    {
        $user = User::factory()->create();
        $this->assertNull($user->mobile_enrolled_at);

        $enrollment = app(ChatEnrollment::class);
        $enrollment->remember($user);
        $first = $user->fresh()->mobile_enrolled_at;
        $this->assertNotNull($first);

        Carbon::setTestNow($first->copy()->addHour());
        $enrollment->remember($user->fresh());
        $this->assertTrue($first->eq($user->fresh()->mobile_enrolled_at));

        $other = User::factory()->create();
        $other->update(['mobile_enrolled_at' => now()]);
        $this->assertNull($other->fresh()->mobile_enrolled_at);
    }

    public function test_intersecting_deputy_periods_are_rejected(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->driver()->create();
        $this->columnRoom('15', $admin);
        $service = app(ChatService::class);
        $service->grant($admin, $user, '15', '2026-10-01', '2026-10-20', 'первая');

        $this->expectException(ValidationException::class);
        $service->grant($admin, $user, '15', '2026-10-20', '2026-10-31', 'пересечение');
    }

    public function test_second_current_or_future_period_is_rejected(): void
    {
        [$authz, $deputy, $room, $period] = $this->grantedDeputy('2026-10-01', '2026-10-31');
        $this->assertTrue($authz->isEffectiveMember($deputy, $room));

        $admin = User::query()->findOrFail($period->granted_by);
        try {
            app(ChatService::class)->grant($admin, $deputy, '15', '2026-11-01', '2026-11-15', 'следующий');
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('starts_on', $e->errors());
        }

        $member = ChatMember::query()->where('room_id', $room->id)->where('user_id', $deputy->id)->firstOrFail();
        $this->assertSame('2026-10-01', $member->window_starts_on->toDateString());
        $this->assertSame('2026-10-31', $member->window_ends_on->toDateString());
        $this->assertTrue($authz->isEffectiveMember($deputy, $room->fresh()));
    }

    public function test_historical_grant_does_not_overwrite_open_snapshot(): void
    {
        [$authz, $deputy, $room, $period] = $this->grantedDeputy('2026-10-01', '2026-10-31');
        $admin = User::query()->findOrFail($period->granted_by);
        app(ChatService::class)->grant($admin, $deputy, '15', '2026-09-01', '2026-09-15', 'архив');

        $member = ChatMember::query()->where('room_id', $room->id)->where('user_id', $deputy->id)->firstOrFail();
        $this->assertSame('2026-10-01', $member->window_starts_on->toDateString());
        $this->assertSame('2026-10-31', $member->window_ends_on->toDateString());
        $this->assertTrue($authz->isEffectiveMember($deputy, $room->fresh()));
        $this->assertSame(2, ColumnDeputy::query()->where('user_id', $deputy->id)->count());
    }

    public function test_revoke_closes_window_on_archived_room_members(): void
    {
        [$authz, $deputy, $room, $period] = $this->grantedDeputy('2026-10-01', '2026-10-31');
        $room->archived_at = now();
        $room->save();

        app(ChatService::class)->revoke($period);

        $room->archived_at = null;
        $room->save();
        $member = ChatMember::query()->where('room_id', $room->id)->where('user_id', $deputy->id)->firstOrFail();
        $this->assertSame('2026-10-19', $member->window_ends_on->toDateString());
        $this->assertFalse($authz->isEffectiveMember($deputy, $room->fresh()));
        $this->assertFalse($authz->roomsFor($deputy)->contains('id', $room->id));
    }

    public function test_assert_mobile_room_other_pilot_column_non_member_is_404(): void
    {
        config(['chat.pilot_column' => '15']);
        $admin = User::factory()->admin()->create();
        $room = $this->columnRoom('99', $admin);
        $stranger = User::factory()->driver()->create();

        $this->assertHttp(app(ChatAuthorization::class), $stranger, $room, 404);
    }

    public function test_assert_mobile_room_other_pilot_column_archived_is_404(): void
    {
        config(['chat.pilot_column' => '15']);
        [$authz, $deputy, $room] = $this->grantedDeputy('2026-10-01', '2026-10-31', '99');
        $room->archived_at = now();
        $room->save();

        $this->assertHttp($authz, $deputy, $room->fresh(), 404);
    }

    public function test_grant_rejects_empty_dates(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->driver()->create();
        $this->columnRoom('15', $admin);

        try {
            app(ChatService::class)->grant($admin, $user, '15', null, '2026-10-31');
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('starts_on', $e->errors());
        }
    }

    /**
     * @return array{0: ChatAuthorization, 1: User, 2: ChatRoom, 3: ColumnDeputy}
     */
    private function grantedDeputy(string $startsOn, string $endsOn, string $column = '15'): array
    {
        $admin = User::factory()->admin()->create();
        $deputy = User::factory()->driver()->create();
        UserProfile::create(['user_id' => $deputy->id, 'column' => $column]);
        $room = $this->columnRoom($column, $admin);
        $period = app(ChatService::class)->grant($admin, $deputy, $column, $startsOn, $endsOn, 'отпуск');

        return [app(ChatAuthorization::class), $deputy, $room, $period];
    }

    private function columnRoom(string $column, User $creator): ChatRoom
    {
        return ChatRoom::create([
            'type' => ChatType::COLUMN,
            'title' => 'Колонна '.$column,
            'column' => $column,
            'created_by' => $creator->id,
        ]);
    }

    private function assertHttp(ChatAuthorization $authz, User $user, ChatRoom $room, int $status): void
    {
        try {
            $authz->assertMobileRoom($user, $room);
            $this->fail('expected HTTP '.$status);
        } catch (HttpException $e) {
            $this->assertSame($status, $e->getStatusCode());
        }
    }
}
