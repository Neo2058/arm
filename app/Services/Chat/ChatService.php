<?php

namespace App\Services\Chat;

use App\Enums\ChatMemberRole;
use App\Enums\ChatMemberSource;
use App\Enums\ChatType;
use App\Models\ChatMember;
use App\Models\ChatRoom;
use App\Models\ColumnDeputy;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ChatService
{
    public function grant(
        User $grantedBy,
        User $user,
        string $column,
        mixed $startsOn,
        mixed $endsOn,
        ?string $reason = null,
    ): ColumnDeputy {
        $column = trim($column);
        [$startsOn, $endsOn] = $this->normalizeWindow($startsOn, $endsOn);

        return DB::transaction(function () use ($grantedBy, $user, $column, $startsOn, $endsOn, $reason) {
            $this->assertNoOverlap($column, $user->id, $startsOn, $endsOn);

            $period = ColumnDeputy::create([
                'column' => $column,
                'user_id' => $user->id,
                'granted_by' => $grantedBy->id,
                'starts_on' => $startsOn,
                'ends_on' => $endsOn,
                'reason' => $reason,
            ]);

            $this->applyWindowToColumnRooms($column, $user->id, $startsOn, $endsOn, $grantedBy->id, true);

            return $period;
        });
    }

    public function updateWindow(ColumnDeputy $period, mixed $startsOn, mixed $endsOn): ColumnDeputy
    {
        [$startsOn, $endsOn] = $this->normalizeWindow($startsOn, $endsOn);

        return DB::transaction(function () use ($period, $startsOn, $endsOn) {
            $period = ColumnDeputy::query()->whereKey($period->id)->lockForUpdate()->firstOrFail();
            $this->assertNoOverlap($period->column, $period->user_id, $startsOn, $endsOn, $period->id);
            $period->starts_on = $startsOn;
            $period->ends_on = $endsOn;
            $period->save();
            $this->applyWindowToColumnRooms($period->column, $period->user_id, $startsOn, $endsOn, null, false);

            return $period;
        });
    }

    public function revoke(ColumnDeputy $period): void
    {
        DB::transaction(function () use ($period) {
            $period = ColumnDeputy::query()->whereKey($period->id)->lockForUpdate()->firstOrFail();
            $yesterday = now()->timezone((string) config('chat.timezone', 'Europe/Moscow'))->subDay()->toDateString();

            if ($period->starts_on->toDateString() > $yesterday) {
                $period->delete();
            } else {
                $period->ends_on = $yesterday;
                $period->save();
            }

            foreach ($this->columnRooms($period->column) as $room) {
                $member = ChatMember::query()
                    ->where('room_id', $room->id)
                    ->where('user_id', $period->user_id)
                    ->first();
                if ($member === null) {
                    continue;
                }
                if ($member->source === ChatMemberSource::DEPUTY) {
                    $member->window_ends_on = $yesterday;
                    $member->save();

                    continue;
                }

                $member->window_starts_on = null;
                $member->window_ends_on = null;
                $member->save();
            }
        });
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function normalizeWindow(mixed $startsOn, mixed $endsOn): array
    {
        $starts = Carbon::parse($startsOn)->toDateString();
        $ends = Carbon::parse($endsOn)->toDateString();
        if ($ends < $starts) {
            throw ValidationException::withMessages([
                'ends_on' => 'Дата окончания не может быть раньше даты начала.',
            ]);
        }

        return [$starts, $ends];
    }

    private function assertNoOverlap(string $column, int $userId, string $startsOn, string $endsOn, ?int $exceptId = null): void
    {
        $query = ColumnDeputy::query()
            ->where('column', $column)
            ->where('user_id', $userId)
            ->where('starts_on', '<=', $endsOn)
            ->where('ends_on', '>=', $startsOn);
        if ($exceptId !== null) {
            $query->where('id', '!=', $exceptId);
        }
        if ($query->exists()) {
            throw ValidationException::withMessages([
                'starts_on' => 'Период замещения пересекается с уже существующим.',
            ]);
        }
    }

    private function applyWindowToColumnRooms(
        string $column,
        int $userId,
        string $startsOn,
        string $endsOn,
        ?int $addedBy,
        bool $createMissing,
    ): void {
        foreach ($this->columnRooms($column) as $room) {
            $member = ChatMember::query()
                ->where('room_id', $room->id)
                ->where('user_id', $userId)
                ->first();
            if ($member === null) {
                if (! $createMissing) {
                    continue;
                }
                ChatMember::create([
                    'room_id' => $room->id,
                    'user_id' => $userId,
                    'role' => ChatMemberRole::MODERATOR,
                    'source' => ChatMemberSource::DEPUTY,
                    'window_starts_on' => $startsOn,
                    'window_ends_on' => $endsOn,
                    'added_by' => $addedBy,
                ]);

                continue;
            }

            $member->window_starts_on = $startsOn;
            $member->window_ends_on = $endsOn;
            $member->save();
        }
    }

    /**
     * @return Collection<int, ChatRoom>
     */
    private function columnRooms(string $column): Collection
    {
        return ChatRoom::query()
            ->where('type', ChatType::COLUMN)
            ->whereNull('archived_at')
            ->whereRaw('trim(column) = ?', [trim($column)])
            ->get();
    }
}
