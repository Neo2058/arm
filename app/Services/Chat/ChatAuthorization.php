<?php

namespace App\Services\Chat;

use App\Enums\ChatMemberSource;
use App\Enums\ChatType;
use App\Models\ChatMember;
use App\Models\ChatRoom;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ChatAuthorization
{
    public function isEffectiveMember(User $user, ChatRoom $room): bool
    {
        if (! $user->is_active || $room->archived_at !== null) {
            return false;
        }

        $member = $this->member($user, $room);

        return $member !== null && $this->sourceAllowsAccess($member);
    }

    public function canManageMembers(User $user, ChatRoom $room): bool
    {
        if ($room->type !== ChatType::COLUMN || ! $this->isEffectiveMember($user, $room)) {
            return false;
        }

        $member = $this->member($user, $room);
        if ($member === null) {
            return false;
        }

        $roomColumn = trim((string) $room->column);
        $profileColumn = trim((string) ($user->profile?->column ?? ''));
        if ($user->isInstructor() && $roomColumn !== '' && $profileColumn === $roomColumn) {
            return true;
        }

        return $this->deputyWindowActive($member->window_starts_on, $member->window_ends_on);
    }

    /**
     * @return Collection<int, ChatRoom>
     */
    public function roomsFor(User $user): Collection
    {
        if (! $user->is_active) {
            return collect();
        }

        $rooms = ChatRoom::query()
            ->whereNull('archived_at')
            ->whereHas('members', fn ($query) => $query->where('user_id', $user->id))
            ->with(['members' => fn ($query) => $query->where('user_id', $user->id)])
            ->orderBy('id')
            ->get()
            ->filter(fn (ChatRoom $room) => $this->isEffectiveMember($user, $room));

        $pilot = trim((string) config('chat.pilot_column'));
        if ($pilot !== '') {
            $rooms = $rooms->filter(function (ChatRoom $room) use ($pilot) {
                if ($room->type !== ChatType::COLUMN) {
                    return true;
                }

                return trim((string) $room->column) === $pilot;
            });
        }

        return $rooms->values();
    }

    public function assertMobileRoom(User $user, ChatRoom $room): void
    {
        if (! config('chat.enabled')) {
            abort(404);
        }

        $member = $this->member($user, $room);
        if (! $user->is_active || $member === null || ! $this->sourceAllowsAccess($member)) {
            abort(403);
        }

        if ($room->archived_at !== null) {
            abort(410, 'Чат архивирован.');
        }

        $pilot = trim((string) config('chat.pilot_column'));
        if ($pilot !== '' && $room->type === ChatType::COLUMN && trim((string) $room->column) !== $pilot) {
            abort(404);
        }
    }

    public function deputyWindowActive(mixed $startsOn, mixed $endsOn): bool
    {
        if ($startsOn === null || $endsOn === null) {
            return false;
        }

        // app.timezone is UTC; deputy windows are inclusive calendar dates in chat.timezone.
        $today = now()->timezone((string) config('chat.timezone', 'Europe/Moscow'))->toDateString();
        $start = Carbon::parse($startsOn)->toDateString();
        $end = Carbon::parse($endsOn)->toDateString();

        return $today >= $start && $today <= $end;
    }

    private function sourceAllowsAccess(ChatMember $member): bool
    {
        if ($member->source === ChatMemberSource::REGULAR) {
            return true;
        }

        if ($member->source === ChatMemberSource::DEPUTY) {
            return $this->deputyWindowActive($member->window_starts_on, $member->window_ends_on);
        }

        return false;
    }

    private function member(User $user, ChatRoom $room): ?ChatMember
    {
        if ($room->relationLoaded('members')) {
            $found = $room->members->firstWhere('user_id', $user->id);

            return $found instanceof ChatMember ? $found : null;
        }

        return ChatMember::query()
            ->where('room_id', $room->id)
            ->where('user_id', $user->id)
            ->first();
    }
}
