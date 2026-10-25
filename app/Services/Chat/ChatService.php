<?php

namespace App\Services\Chat;

use App\Enums\ChatMemberRole;
use App\Enums\ChatMemberSource;
use App\Enums\ChatType;
use App\Models\ChatMember;
use App\Models\ChatRoom;
use App\Models\ColumnDeputy;
use App\Models\User;
use DateTimeInterface;
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
            $this->assertSingleOpenPeriod($column, $user->id, $endsOn);

            $period = ColumnDeputy::create([
                'column' => $column,
                'user_id' => $user->id,
                'granted_by' => $grantedBy->id,
                'starts_on' => $startsOn,
                'ends_on' => $endsOn,
                'reason' => $reason,
            ]);

            $this->syncMemberWindows($column, $user->id, $grantedBy->id, true);

            return $period;
        });
    }

    public function updateWindow(ColumnDeputy $period, mixed $startsOn, mixed $endsOn): ColumnDeputy
    {
        [$startsOn, $endsOn] = $this->normalizeWindow($startsOn, $endsOn);

        return DB::transaction(function () use ($period, $startsOn, $endsOn) {
            $period = ColumnDeputy::query()->whereKey($period->id)->lockForUpdate()->firstOrFail();
            $this->assertSingleOpenPeriod($period->column, $period->user_id, $endsOn, $period->id);
            $period->starts_on = $startsOn;
            $period->ends_on = $endsOn;
            $period->save();
            $this->syncMemberWindows($period->column, $period->user_id, null, false);

            return $period;
        });
    }

    public function revoke(ColumnDeputy $period): void
    {
        DB::transaction(function () use ($period) {
            $period = ColumnDeputy::query()->whereKey($period->id)->lockForUpdate()->firstOrFail();
            $column = $period->column;
            $userId = $period->user_id;
            $yesterday = now()->timezone((string) config('chat.timezone', 'Europe/Moscow'))->subDay()->toDateString();

            if ($period->starts_on->toDateString() > $yesterday) {
                $period->delete();
            } else {
                $period->ends_on = $yesterday;
                $period->save();
            }

            $this->syncMemberWindows($column, $userId, null, false);
        });
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function normalizeWindow(mixed $startsOn, mixed $endsOn): array
    {
        $starts = $this->normalizeDate($startsOn, 'starts_on');
        $ends = $this->normalizeDate($endsOn, 'ends_on');
        if ($ends < $starts) {
            throw ValidationException::withMessages([
                'ends_on' => 'Дата окончания не может быть раньше даты начала.',
            ]);
        }

        return [$starts, $ends];
    }

    private function normalizeDate(mixed $value, string $field): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (! is_string($value) || trim($value) === '') {
            throw ValidationException::withMessages([
                $field => 'Укажите дату.',
            ]);
        }

        return Carbon::parse($value)->toDateString();
    }

    private function assertSingleOpenPeriod(string $column, int $userId, string $endsOn, ?int $exceptId = null): void
    {
        $today = $this->today();
        if ($endsOn < $today) {
            return;
        }

        $query = ColumnDeputy::query()
            ->where('column', $column)
            ->where('user_id', $userId)
            ->where('ends_on', '>=', $today)
            ->lockForUpdate();
        if ($exceptId !== null) {
            $query->where('id', '!=', $exceptId);
        }
        if ($query->exists()) {
            throw ValidationException::withMessages([
                'starts_on' => 'Уже есть действующий или будущий период замещения по этой колонне.',
            ]);
        }
    }

    private function syncMemberWindows(string $column, int $userId, ?int $addedBy, bool $createMissing): void
    {
        $open = $this->openPeriod($column, $userId);
        if ($open === null) {
            $this->closeMemberWindows($column, $userId);

            return;
        }

        $this->applyWindowToColumnRooms(
            $column,
            $userId,
            $open->starts_on->toDateString(),
            $open->ends_on->toDateString(),
            $addedBy,
            $createMissing,
        );
    }

    private function openPeriod(string $column, int $userId): ?ColumnDeputy
    {
        return ColumnDeputy::query()
            ->where('column', $column)
            ->where('user_id', $userId)
            ->where('ends_on', '>=', $this->today())
            ->orderBy('starts_on')
            ->orderBy('id')
            ->first();
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
                if (! $createMissing || $room->archived_at !== null) {
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

    private function closeMemberWindows(string $column, int $userId): void
    {
        $yesterday = now()->timezone((string) config('chat.timezone', 'Europe/Moscow'))->subDay()->toDateString();
        foreach ($this->columnRooms($column) as $room) {
            $member = ChatMember::query()
                ->where('room_id', $room->id)
                ->where('user_id', $userId)
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
    }

    /**
     * @return Collection<int, ChatRoom>
     */
    private function columnRooms(string $column): Collection
    {
        return ChatRoom::query()
            ->where('type', ChatType::COLUMN)
            ->whereRaw('trim(column) = ?', [trim($column)])
            ->get();
    }

    private function today(): string
    {
        return now()->timezone((string) config('chat.timezone', 'Europe/Moscow'))->toDateString();
    }
}
