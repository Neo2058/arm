<?php

namespace App\Services\Chat;

use App\Enums\ChatMemberRole;
use App\Enums\ChatMemberSource;
use App\Enums\ChatMessageStatus;
use App\Enums\ChatMessageType;
use App\Enums\ChatType;
use App\Enums\UserRole;
use App\Jobs\ProcessChatAttachment;
use App\Models\ChatMember;
use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\ColumnDeputy;
use App\Models\User;
use App\Services\FileProxyService;
use DateTimeInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ChatService
{
    public function __construct(
        private ChatAuthorization $authz,
        private ChatCrypto $crypto,
        private FileProxyService $files,
    ) {}

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
     * @param  array<string, mixed>  $payload
     * @param  list<array<string, mixed>>  $members
     */
    public function createRoom(User $creator, array $payload, array $members = []): ChatRoom
    {
        $type = $this->resolveType($payload['type'] ?? null);
        $column = $this->normalizeRoomColumn($type, $payload['column'] ?? null);
        $title = trim((string) ($payload['title'] ?? ''));
        if ($title === '') {
            throw ValidationException::withMessages([
                'title' => 'Укажите название.',
            ]);
        }

        return DB::transaction(function () use ($creator, $type, $column, $title, $payload, $members) {
            $room = ChatRoom::create([
                'type' => $type,
                'title' => $title,
                'column' => $column,
                'created_by' => $creator->id,
                'archived_at' => ! empty($payload['archived']) ? now() : null,
            ]);
            $this->syncAdminRegularMembers($room, $creator, $members);
            $this->seedColumnRoom($room, $creator);

            return $room;
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<array<string, mixed>>  $members
     */
    public function updateRoom(ChatRoom $room, User $actor, array $payload, array $members = []): ChatRoom
    {
        $type = array_key_exists('type', $payload)
            ? $this->resolveType($payload['type'])
            : $room->type;
        $column = array_key_exists('column', $payload)
            ? $this->normalizeRoomColumn($type, $payload['column'])
            : $this->normalizeRoomColumn($type, $room->column);
        $title = array_key_exists('title', $payload)
            ? trim((string) $payload['title'])
            : $room->title;
        if ($title === '') {
            throw ValidationException::withMessages([
                'title' => 'Укажите название.',
            ]);
        }

        return DB::transaction(function () use ($room, $actor, $type, $column, $title, $payload, $members) {
            $room = ChatRoom::query()->whereKey($room->id)->lockForUpdate()->firstOrFail();
            $room->type = $type;
            $room->title = $title;
            $room->column = $column;
            if (array_key_exists('archived', $payload)) {
                $room->archived_at = ! empty($payload['archived'])
                    ? ($room->archived_at ?? now())
                    : null;
            }
            $room->save();
            $this->syncAdminRegularMembers($room, $actor, $members);

            return $room;
        });
    }

    public function seedColumnRoom(ChatRoom $room, User $actor): void
    {
        if ($room->type !== ChatType::COLUMN) {
            return;
        }

        $column = trim((string) $room->column);
        if ($column === '') {
            return;
        }

        $instructorIds = User::query()
            ->where('is_active', true)
            ->where('role', UserRole::INSTRUCTOR)
            ->whereHas('profile', fn ($query) => $query->whereRaw('trim(column) = ?', [$column]))
            ->pluck('id');
        foreach ($instructorIds as $userId) {
            $this->ensureRegularMember($room, (int) $userId, ChatMemberRole::MODERATOR, $actor->id);
        }

        $deputyUserIds = ColumnDeputy::query()
            ->whereRaw('trim(column) = ?', [$column])
            ->where('ends_on', '>=', $this->today())
            ->pluck('user_id')
            ->unique();
        foreach ($deputyUserIds as $userId) {
            $this->syncMemberWindows($column, (int) $userId, $actor->id, true);
        }
    }

    /**
     * @return array{server_time: string, rooms: list<array<string, mixed>>}
     */
    public function inbox(User $user): array
    {
        $user->loadMissing('profile');
        $rooms = $this->authz->roomsFor($user);
        $ids = $rooms->pluck('id')->all();
        $lastByRoom = collect();
        $unreadByRoom = collect();
        if ($ids !== []) {
            $lastByRoom = ChatMessage::query()
                ->with('sender:id,name')
                ->whereIn('id', ChatMessage::query()
                    ->selectRaw('MAX(id)')
                    ->whereIn('room_id', $ids)
                    ->where('status', ChatMessageStatus::AVAILABLE)
                    ->groupBy('room_id'))
                ->get()
                ->keyBy('room_id');

            $unreadByRoom = ChatMessage::query()
                ->selectRaw('room_id, COUNT(*) as unread')
                ->whereIn('room_id', $ids)
                ->whereNull('deleted_at')
                ->where('status', ChatMessageStatus::AVAILABLE)
                ->where(function ($query) use ($rooms, $user) {
                    foreach ($rooms as $room) {
                        $lastRead = (int) ($room->members->firstWhere('user_id', $user->id)?->last_read_message_id ?? 0);
                        $query->orWhere(function ($inner) use ($room, $lastRead) {
                            $inner->where('room_id', $room->id)->where('id', '>', $lastRead);
                        });
                    }
                })
                ->groupBy('room_id')
                ->pluck('unread', 'room_id');
        }

        $payload = [];
        foreach ($rooms as $room) {
            $last = $lastByRoom->get($room->id);
            $payload[] = [
                'id' => $room->id,
                'type' => $room->type->value,
                'title' => $room->title,
                'column' => $room->column,
                'unread' => (int) ($unreadByRoom[$room->id] ?? 0),
                'can_manage_members' => $this->authz->canManageMembers($user, $room),
                'last_message' => $last instanceof ChatMessage ? $this->presentLastMessage($last) : null,
            ];
        }

        return [
            'server_time' => $this->iso(now()),
            'rooms' => $payload,
        ];
    }

    /**
     * @return array{id: int, type: string, title: string, column: ?string, can_manage_members: bool, members_count: int}
     */
    public function roomMeta(User $user, ChatRoom $room): array
    {
        $user->loadMissing('profile');

        return [
            'id' => $room->id,
            'type' => $room->type->value,
            'title' => $room->title,
            'column' => $room->column,
            'can_manage_members' => $this->authz->canManageMembers($user, $room),
            'members_count' => count($this->members($user, $room)),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function thread(User $user, ChatRoom $room, ?int $afterId, ?int $beforeId, int $limit): array
    {
        $query = ChatMessage::query()
            ->with(['sender:id,name', 'attachment'])
            ->where('room_id', $room->id)
            ->where(function ($inner) use ($user) {
                $inner->where('status', ChatMessageStatus::AVAILABLE)
                    ->orWhere(function ($rejected) use ($user) {
                        $rejected->where('status', ChatMessageStatus::REJECTED)
                            ->where('sender_id', $user->id);
                    });
            });
        if ($afterId !== null) {
            $query->where('id', '>', $afterId);
        }
        if ($beforeId !== null) {
            $query->where('id', '<', $beforeId);
        }

        $newestFirst = $afterId === null || $beforeId !== null;
        $rows = $newestFirst
            ? $query->orderByDesc('id')->limit($limit)->get()->sortBy('id')->values()
            : $query->orderBy('id')->limit($limit)->get();

        return $rows->map(fn (ChatMessage $message) => $this->presentMessage($message))->all();
    }

    /**
     * @return array{message: ChatMessage, created: bool}
     */
    public function sendText(User $user, ChatRoom $room, string $clientId, string $body): array
    {
        $fingerprint = $this->fingerprint(ChatMessageType::TEXT->value, $body);

        return Cache::lock('chat-send:'.$user->id.':'.$clientId, 30)->block(10, function () use ($user, $room, $clientId, $body, $fingerprint) {
            return DB::transaction(function () use ($user, $room, $clientId, $body, $fingerprint) {
                $this->authz->assertMobileRoom($user, $room->fresh());

                $existing = ChatMessage::query()
                    ->where('room_id', $room->id)
                    ->where('sender_id', $user->id)
                    ->where('client_id', $clientId)
                    ->lockForUpdate()
                    ->first();
                if ($existing !== null) {
                    if (! is_string($existing->payload_fingerprint)
                        || ! hash_equals($existing->payload_fingerprint, $fingerprint)) {
                        abort(409, 'Этот client_id уже использован с другим содержимым.');
                    }

                    return ['message' => $existing, 'created' => false];
                }

                $message = ChatMessage::create([
                    'room_id' => $room->id,
                    'sender_id' => $user->id,
                    'client_id' => $clientId,
                    'payload_fingerprint' => $fingerprint,
                    'type' => ChatMessageType::TEXT,
                    'status' => ChatMessageStatus::AVAILABLE,
                    'body_ciphertext' => $this->crypto->encrypt($body),
                ]);

                return ['message' => $message, 'created' => true];
            });
        });
    }

    /**
     * @return array{message: ChatMessage, created: bool}
     */
    public function sendAttachment(
        User $user,
        ChatRoom $room,
        string $clientId,
        ChatMessageType $type,
        UploadedFile $file,
        string $body = '',
    ): array {
        $validated = $this->assertUpload($type, $file);
        $fingerprint = $this->fingerprint($type->value, $body, $validated['sha256']);

        return Cache::lock('chat-send:'.$user->id.':'.$clientId, 30)->block(10, function () use ($user, $room, $clientId, $type, $file, $body, $fingerprint, $validated) {
            return DB::transaction(function () use ($user, $room, $clientId, $type, $file, $body, $fingerprint, $validated) {
                $this->authz->assertMobileRoom($user, $room->fresh());

                $existing = ChatMessage::query()
                    ->where('room_id', $room->id)
                    ->where('sender_id', $user->id)
                    ->where('client_id', $clientId)
                    ->lockForUpdate()
                    ->first();
                if ($existing !== null) {
                    if (! is_string($existing->payload_fingerprint)
                        || ! hash_equals($existing->payload_fingerprint, $fingerprint)) {
                        abort(409, 'Этот client_id уже использован с другим содержимым.');
                    }

                    return ['message' => $existing, 'created' => false];
                }

                $relative = null;
                try {
                    $message = ChatMessage::create([
                        'room_id' => $room->id,
                        'sender_id' => $user->id,
                        'client_id' => $clientId,
                        'payload_fingerprint' => $fingerprint,
                        'type' => $type,
                        'status' => ChatMessageStatus::QUEUED,
                        'body_ciphertext' => null,
                    ]);
                    $relative = 'chat-pending/'.$room->id.'/'.$message->id;
                    $stream = fopen($file->getRealPath(), 'rb');
                    try {
                        abort_unless(
                            Storage::disk('local')->put($relative, $stream),
                            503,
                            'Хранилище недоступно.',
                        );
                    } finally {
                        if (is_resource($stream)) {
                            fclose($stream);
                        }
                    }
                    ProcessChatAttachment::dispatch(
                        $message->id,
                        $relative,
                        $validated['sha256'],
                        $validated['name'],
                        $validated['mime'],
                        $validated['size'],
                        $body,
                    );
                } catch (UniqueConstraintViolationException) {
                    if ($relative !== null) {
                        Storage::disk('local')->delete($relative);
                    }
                    $existing = ChatMessage::query()
                        ->where('room_id', $room->id)
                        ->where('sender_id', $user->id)
                        ->where('client_id', $clientId)
                        ->first();
                    if ($existing !== null
                        && is_string($existing->payload_fingerprint)
                        && hash_equals($existing->payload_fingerprint, $fingerprint)) {
                        return ['message' => $existing, 'created' => false];
                    }
                    abort(409, 'Этот client_id уже использован с другим содержимым.');
                } catch (\Throwable $e) {
                    if ($relative !== null) {
                        Storage::disk('local')->delete($relative);
                    }
                    throw $e;
                }

                return ['message' => $message, 'created' => true];
            });
        });
    }

    public function markRead(User $user, ChatRoom $room, int $messageId): void
    {
        $belongs = ChatMessage::query()->where('room_id', $room->id)->whereKey($messageId)->exists();
        if (! $belongs) {
            throw ValidationException::withMessages([
                'last_read_message_id' => 'Сообщение не принадлежит этой комнате.',
            ]);
        }

        ChatMember::query()
            ->where('room_id', $room->id)
            ->where('user_id', $user->id)
            ->where(function ($query) use ($messageId) {
                $query->whereNull('last_read_message_id')
                    ->orWhere('last_read_message_id', '<', $messageId);
            })
            ->update([
                'last_read_message_id' => $messageId,
                'last_read_at' => now(),
            ]);
    }

    /**
     * @return list<array{id: int, name: string, role: string, source: string}>
     */
    public function members(User $user, ChatRoom $room): array
    {
        $room->load(['members.user']);
        $out = [];
        foreach ($room->members as $member) {
            $memberUser = $member->user;
            if ($memberUser === null || ! $this->authz->isEffectiveMember($memberUser, $room)) {
                continue;
            }
            $out[] = [
                'id' => $memberUser->id,
                'name' => $memberUser->name,
                'role' => $member->role->value,
                'source' => $member->source->value,
            ];
        }
        usort($out, fn (array $a, array $b) => strcasecmp($a['name'], $b['name']));

        return $out;
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    public function eligible(User $user, ChatRoom $room, string $q): array
    {
        $user->loadMissing('profile');
        if (! $this->authz->canManageMembers($user, $room)) {
            abort(403);
        }

        $column = trim((string) $room->column);
        $memberIds = ChatMember::query()->where('room_id', $room->id)->pluck('user_id');
        $query = User::query()
            ->where('is_active', true)
            ->whereNotNull('mobile_enrolled_at')
            ->whereHas('profile', fn ($profile) => $profile->whereRaw('trim(column) = ?', [$column]))
            ->whereNotIn('id', $memberIds)
            ->orderBy('name')
            ->limit(100);
        $q = trim($q);
        if ($q !== '') {
            $pattern = '%'.addcslashes($q, '%_\\').'%';
            $operator = $query->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
            $query->where('name', $operator, $pattern);
        }

        return $query->get(['id', 'name'])->map(fn (User $candidate) => [
            'id' => $candidate->id,
            'name' => $candidate->name,
        ])->all();
    }

    public function addMember(User $actor, ChatRoom $room, User $target): void
    {
        $actor->loadMissing('profile');
        $target->loadMissing('profile');
        if (! $this->authz->canManageMembers($actor, $room)) {
            abort(403);
        }

        $roomColumn = trim((string) $room->column);
        $targetColumn = trim((string) ($target->profile?->column ?? ''));
        if (! $target->is_active) {
            throw ValidationException::withMessages([
                'user_id' => 'Пользователь неактивен.',
            ]);
        }
        if ($target->mobile_enrolled_at === null) {
            throw ValidationException::withMessages([
                'user_id' => 'Пользователь не устанавливал приложение.',
            ]);
        }
        if ($room->type !== ChatType::COLUMN || $roomColumn === '' || $targetColumn !== $roomColumn) {
            throw ValidationException::withMessages([
                'user_id' => 'Пользователь из другой колонны.',
            ]);
        }

        try {
            DB::transaction(function () use ($actor, $room, $target) {
                ChatRoom::query()->whereKey($room->id)->lockForUpdate()->firstOrFail();
                if (ChatMember::query()->where('room_id', $room->id)->where('user_id', $target->id)->exists()) {
                    throw ValidationException::withMessages([
                        'user_id' => 'Пользователь уже в чате.',
                    ]);
                }

                ChatMember::create([
                    'room_id' => $room->id,
                    'user_id' => $target->id,
                    'role' => ChatMemberRole::MEMBER,
                    'source' => ChatMemberSource::REGULAR,
                    'added_by' => $actor->id,
                ]);
                ChatMessage::create([
                    'room_id' => $room->id,
                    'sender_id' => null,
                    'client_id' => null,
                    'type' => ChatMessageType::SYSTEM,
                    'status' => ChatMessageStatus::AVAILABLE,
                    'body_ciphertext' => $this->crypto->encrypt('Добавлен '.$target->name),
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'user_id' => 'Пользователь уже в чате.',
            ]);
        }
    }

    public function removeMember(User $actor, ChatRoom $room, User $target): void
    {
        $actor->loadMissing('profile');
        if (! $this->authz->canManageMembers($actor, $room)) {
            abort(403);
        }
        if ($actor->id === $target->id) {
            throw ValidationException::withMessages([
                'user' => 'Нельзя удалить себя.',
            ]);
        }

        $member = ChatMember::query()->where('room_id', $room->id)->where('user_id', $target->id)->first();
        if ($member === null) {
            throw ValidationException::withMessages([
                'user' => 'Пользователь не состоит в чате.',
            ]);
        }
        if ($member->source === ChatMemberSource::DEPUTY) {
            throw ValidationException::withMessages([
                'user' => 'Снимите заместителя в админке.',
            ]);
        }
        if ($member->role === ChatMemberRole::MODERATOR && $member->source === ChatMemberSource::REGULAR) {
            $hasOther = ChatMember::query()
                ->where('room_id', $room->id)
                ->where('id', '!=', $member->id)
                ->where('role', ChatMemberRole::MODERATOR)
                ->where('source', ChatMemberSource::REGULAR)
                ->exists();
            if (! $hasOther) {
                throw ValidationException::withMessages([
                    'user' => 'Нельзя удалить последнего модератора.',
                ]);
            }
        }

        $member->delete();
    }

    private function resolveType(mixed $type): ChatType
    {
        if ($type instanceof ChatType) {
            return $type;
        }
        $resolved = ChatType::tryFrom((string) $type);
        if ($resolved === null) {
            throw ValidationException::withMessages([
                'type' => 'Укажите тип чата.',
            ]);
        }

        return $resolved;
    }

    private function normalizeRoomColumn(ChatType $type, mixed $column): ?string
    {
        $column = is_string($column) || is_numeric($column) ? trim((string) $column) : '';

        return match ($type) {
            ChatType::COLUMN => $column !== '' ? $column : throw ValidationException::withMessages([
                'column' => 'Для чата колонны укажите колонну.',
            ]),
            ChatType::LEADERSHIP => null,
            ChatType::WORK => $column !== '' ? $column : null,
        };
    }

    /**
     * @param  list<array<string, mixed>>|array<string, array<string, mixed>>  $members
     */
    private function syncAdminRegularMembers(ChatRoom $room, User $actor, array $members): void
    {
        $keep = [];
        foreach ($members as $row) {
            if (! is_array($row)) {
                continue;
            }
            $userId = (int) ($row['user_id'] ?? 0);
            if ($userId === 0) {
                continue;
            }
            $target = User::query()->whereKey($userId)->where('is_active', true)->first();
            if ($target === null) {
                continue;
            }
            $role = ChatMemberRole::tryFrom((string) ($row['role'] ?? '')) ?? ChatMemberRole::MEMBER;
            $existing = ChatMember::query()
                ->where('room_id', $room->id)
                ->where('user_id', $userId)
                ->first();
            if ($existing === null) {
                ChatMember::create([
                    'room_id' => $room->id,
                    'user_id' => $userId,
                    'role' => $role,
                    'source' => ChatMemberSource::REGULAR,
                    'added_by' => $actor->id,
                ]);
            } elseif ($existing->source === ChatMemberSource::REGULAR) {
                $existing->role = $role;
                $existing->save();
            }
            $keep[] = $userId;
        }

        $query = ChatMember::query()
            ->where('room_id', $room->id)
            ->where('source', ChatMemberSource::REGULAR);
        if ($keep !== []) {
            $query->whereNotIn('user_id', $keep);
        }
        $query->delete();
    }

    private function ensureRegularMember(ChatRoom $room, int $userId, ChatMemberRole $role, int $addedBy): void
    {
        if (ChatMember::query()->where('room_id', $room->id)->where('user_id', $userId)->exists()) {
            return;
        }

        ChatMember::create([
            'room_id' => $room->id,
            'user_id' => $userId,
            'role' => $role,
            'source' => ChatMemberSource::REGULAR,
            'added_by' => $addedBy,
        ]);
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

    private function fingerprint(string $type, string $body, string $fileSha256 = ''): string
    {
        return hash('sha256', $type."\n".$body."\n".$fileSha256);
    }

    /**
     * @return array{id: int, type: string, preview: string, sender_name: ?string, created_at: string}
     */
    private function presentLastMessage(ChatMessage $message): array
    {
        $deleted = $message->deleted_at !== null || $message->type === ChatMessageType::DELETED;

        return [
            'id' => $message->id,
            'type' => $deleted ? ChatMessageType::DELETED->value : $message->type->value,
            'preview' => $this->preview($message),
            'sender_name' => $message->sender?->name,
            'created_at' => $this->iso($message->created_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentMessage(ChatMessage $message): array
    {
        if ($message->status === ChatMessageStatus::REJECTED) {
            return [
                'id' => $message->id,
                'client_id' => $message->client_id,
                'status' => ChatMessageStatus::REJECTED->value,
            ];
        }
        if ($message->deleted_at !== null || $message->type === ChatMessageType::DELETED) {
            return [
                'id' => $message->id,
                'type' => ChatMessageType::DELETED->value,
                'created_at' => $this->iso($message->created_at),
            ];
        }

        $row = [
            'id' => $message->id,
            'type' => $message->type->value,
            'sender_id' => $message->sender_id,
            'sender_name' => $message->sender?->name,
            'client_id' => $message->client_id,
            'created_at' => $this->iso($message->created_at),
            'status' => $message->status->value,
        ];
        if ($message->type === ChatMessageType::TEXT || $message->type === ChatMessageType::SYSTEM) {
            $row['body'] = $message->body_ciphertext !== null && $message->body_ciphertext !== ''
                ? $this->crypto->decrypt($message->body_ciphertext)
                : null;
        }
        if ($message->type === ChatMessageType::IMAGE || $message->type === ChatMessageType::PDF) {
            if ($message->body_ciphertext !== null && $message->body_ciphertext !== '') {
                $row['body'] = $this->crypto->decrypt($message->body_ciphertext);
            }
            $attachment = $message->attachment;
            if ($attachment !== null) {
                $row['file'] = [
                    'id' => $attachment->id,
                    'name' => $attachment->original_name,
                    'mime' => $attachment->mime,
                    'size' => $attachment->size,
                    'url' => $this->files->temporarySignedRoute('mobile.files.chats', 15, $attachment->id),
                ];
            }
        }

        return $row;
    }

    /**
     * @return array{name: string, mime: string, size: int, sha256: string}
     */
    private function assertUpload(ChatMessageType $type, UploadedFile $file): array
    {
        $max = (int) config('chat.attachment_max_bytes', 10 * 1024 * 1024);
        if (! $file->isValid() || $file->getSize() <= 0 || $file->getSize() > $max) {
            throw ValidationException::withMessages([
                'file' => 'Допустимы файлы размером от 1 байта до 10 МиБ.',
            ]);
        }

        $mime = (string) $file->getMimeType();
        $extension = strtolower($file->getClientOriginalExtension());
        $types = [
            'image/jpeg' => ['jpg', 'jpeg'],
            'image/png' => ['png'],
            'application/pdf' => ['pdf'],
        ];
        if (! in_array($extension, $types[$mime] ?? [], true)) {
            throw ValidationException::withMessages([
                'file' => 'Допустимы только PDF, JPEG и PNG с соответствующим расширением.',
            ]);
        }
        if ($type === ChatMessageType::PDF && $mime !== 'application/pdf') {
            throw ValidationException::withMessages([
                'file' => 'Допустимы только PDF, JPEG и PNG с соответствующим расширением.',
            ]);
        }
        if ($type === ChatMessageType::IMAGE && ! in_array($mime, ['image/jpeg', 'image/png'], true)) {
            throw ValidationException::withMessages([
                'file' => 'Допустимы только PDF, JPEG и PNG с соответствующим расширением.',
            ]);
        }

        $name = basename(str_replace('\\', '/', $file->getClientOriginalName()));
        if ($name === '' || mb_strlen($name) > 255 || preg_match('/[\x00-\x1f\x7f]/', $name)) {
            throw ValidationException::withMessages([
                'file' => 'Недопустимое имя файла.',
            ]);
        }

        return [
            'name' => $name,
            'mime' => $mime,
            'size' => (int) $file->getSize(),
            'sha256' => hash_file('sha256', $file->getRealPath()),
        ];
    }

    private function preview(ChatMessage $message): string
    {
        if ($message->deleted_at !== null || $message->type === ChatMessageType::DELETED) {
            return 'Сообщение удалено';
        }

        return match ($message->type) {
            ChatMessageType::IMAGE => 'Фото',
            ChatMessageType::PDF => 'PDF',
            ChatMessageType::SYSTEM => 'Системное',
            ChatMessageType::TEXT => $this->crypto->decrypt((string) $message->body_ciphertext),
            default => '',
        };
    }

    private function iso(mixed $at): string
    {
        return Carbon::parse($at)->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
