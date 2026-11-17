<?php

namespace App\Services\Notifications;

use App\Enums\ChatMessageStatus;
use App\Enums\ChatMessageType;
use App\Enums\ChatType;
use App\Enums\UserNotificationType;
use App\Enums\UserRole;
use App\Models\ChatMember;
use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\DisciplineAssignment;
use App\Models\PushSubscription;
use App\Models\User;
use App\Models\UserFormularLog;
use App\Models\UserNotification;
use App\Services\Chat\ChatAuthorization;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class UserNotifications
{
    public function __construct(private ChatAuthorization $authz) {}

    public function enabled(): bool
    {
        return filter_var(config('notifications.enabled'), FILTER_VALIDATE_BOOLEAN);
    }

    public function chatMessage(ChatMessage $message): void
    {
        if (! $this->enabled()) {
            return;
        }
        if ($message->status !== ChatMessageStatus::AVAILABLE) {
            return;
        }
        if (in_array($message->type, [ChatMessageType::SYSTEM, ChatMessageType::DELETED], true)) {
            return;
        }

        $room = $message->relationLoaded('room') ? $message->room : ChatRoom::query()->find($message->room_id);
        if (! $room instanceof ChatRoom || $room->archived_at !== null) {
            return;
        }

        $title = 'Новое сообщение в чате «'.$this->safeTitle((string) $room->title).'»';
        $senderId = (int) $message->sender_id;
        $userIds = [];
        $members = ChatMember::query()
            ->where('room_id', $room->id)
            ->with('user.profile')
            ->get();

        foreach ($members as $member) {
            $user = $member->user;
            if (! $user instanceof User || (int) $user->id === $senderId || ! $user->is_active) {
                continue;
            }
            if (! $this->authz->isEffectiveMember($user, $room)) {
                continue;
            }
            $pilot = trim((string) config('chat.pilot_column'));
            if ($pilot !== '' && $room->type === ChatType::COLUMN && trim((string) $room->column) !== $pilot) {
                continue;
            }
            $this->upsertChat((int) $user->id, (int) $room->id, $title);
            $userIds[] = (int) $user->id;
        }

        $this->pingUsers($userIds);
    }

    public function formular(string $action, UserFormularLog $log, User $actor): void
    {
        if (! $this->enabled()) {
            return;
        }

        $actor->loadMissing('profile');
        if ($action === 'submit') {
            $column = trim((string) ($actor->profile?->column ?? ''));
            $ids = User::query()
                ->where('is_active', true)
                ->whereKeyNot($actor->id)
                ->where(function ($query) use ($column) {
                    $query->where('role', UserRole::SUPER_ADMIN);
                    if ($column !== '') {
                        $query->orWhere(function ($instructors) use ($column) {
                            $instructors->where('role', UserRole::INSTRUCTOR)
                                ->whereHas('profile', fn ($profile) => $profile->where('column', $column));
                        });
                    }
                })
                ->pluck('id')
                ->all();
            $this->insertMany($ids, UserNotificationType::FormularEntry, 'formular_log', (int) $log->id, 'Новая запись в формуляр');

            return;
        }

        if (! in_array($action, ['confirmed', 'returned'], true)) {
            return;
        }
        if ((int) $log->user_id === (int) $actor->id) {
            return;
        }

        $title = $action === 'confirmed'
            ? 'Запись в формуляр подтверждена'
            : 'Запись в формуляр возвращена';
        $this->insertMany([(int) $log->user_id], UserNotificationType::FormularReview, 'formular_log', (int) $log->id, $title);
    }

    /**
     * @param  list<int|string>  $ids
     */
    public function instructorNotice(array $ids, DisciplineAssignment $assignment, string $event): void
    {
        if (! $this->enabled()) {
            return;
        }

        $title = match ($event) {
            'assigned' => 'Новое поручение',
            'submitted' => 'Отчёт по поручению отправлен',
            'accepted' => 'Поручение принято',
            'returned' => 'Поручение возвращено',
            default => 'Новое поручение',
        };
        $this->insertMany($ids, UserNotificationType::InstructorNotice, 'discipline_assignment', (int) $assignment->id, $title);
    }

    public function markChatRoomRead(User $user, ChatRoom $room): void
    {
        UserNotification::query()
            ->where('user_id', $user->id)
            ->where('type', UserNotificationType::ChatMessage)
            ->where('resource_id', $room->id)
            ->whereNull('read_at')
            ->update(['read_at' => now(), 'updated_at' => now()]);
    }

    public function markRead(User $user, int $id): ?UserNotification
    {
        $row = UserNotification::query()
            ->where('user_id', $user->id)
            ->whereKey($id)
            ->first();
        if ($row === null) {
            return null;
        }
        if ($row->read_at === null) {
            $row->read_at = now();
            $row->save();
        }

        return $row;
    }

    public function markAllRead(User $user): int
    {
        return UserNotification::query()
            ->where('user_id', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now(), 'updated_at' => now()]);
    }

    /**
     * @return array{unread: int, server_time: string, items: list<array<string, mixed>>}
     */
    public function inbox(User $user, bool $unreadOnly = true, int $limit = 50): array
    {
        $query = UserNotification::query()
            ->where('user_id', $user->id)
            ->orderByDesc('id');
        if ($unreadOnly) {
            $query->whereNull('read_at');
        }
        $items = $query->limit($limit)->get();
        $unread = UserNotification::query()
            ->where('user_id', $user->id)
            ->whereNull('read_at')
            ->count();

        return [
            'unread' => $unread,
            'server_time' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'items' => $items->map(fn (UserNotification $row) => $row->payload())->all(),
        ];
    }

    /**
     * @param  array{endpoint: string, keys?: array{p256dh?: string, auth?: string}}  $subscription
     */
    public function saveWebSubscription(User $user, array $subscription, ?string $userAgent = null): PushSubscription
    {
        $endpoint = trim((string) ($subscription['endpoint'] ?? ''));
        if ($endpoint === '' || strlen($endpoint) > 512 || ! str_starts_with($endpoint, 'https://')) {
            abort(422, 'Некорректная подписка на уведомления.');
        }

        return PushSubscription::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'endpoint' => $endpoint,
            ],
            [
                'channel' => 'web',
                'public_key' => $this->clip((string) ($subscription['keys']['p256dh'] ?? ''), 191),
                'auth_token' => $this->clip((string) ($subscription['keys']['auth'] ?? ''), 191),
                'user_agent' => $this->clip((string) ($userAgent ?? ''), 255),
            ],
        );
    }

    public function forgetSubscription(User $user, string $endpoint): void
    {
        PushSubscription::query()
            ->where('user_id', $user->id)
            ->where('endpoint', $endpoint)
            ->delete();
    }

    private function upsertChat(int $userId, int $roomId, string $title): void
    {
        try {
            DB::transaction(function () use ($userId, $roomId, $title) {
                $existing = UserNotification::query()
                    ->where('user_id', $userId)
                    ->where('type', UserNotificationType::ChatMessage)
                    ->where('resource_id', $roomId)
                    ->whereNull('read_at')
                    ->lockForUpdate()
                    ->first();
                if ($existing instanceof UserNotification) {
                    $existing->count = (int) $existing->count + 1;
                    $existing->title = $title;
                    $existing->updated_at = now();
                    $existing->save();

                    return;
                }

                UserNotification::query()->create([
                    'user_id' => $userId,
                    'type' => UserNotificationType::ChatMessage,
                    'resource_type' => 'chat_room',
                    'resource_id' => $roomId,
                    'title' => $title,
                    'count' => 1,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            UserNotification::query()
                ->where('user_id', $userId)
                ->where('type', UserNotificationType::ChatMessage)
                ->where('resource_id', $roomId)
                ->whereNull('read_at')
                ->update([
                    'count' => DB::raw('count + 1'),
                    'title' => $title,
                    'updated_at' => now(),
                ]);
        }
    }

    /**
     * @param  list<int|string>  $ids
     */
    private function insertMany(array $ids, UserNotificationType $type, string $resourceType, int $resourceId, string $title): void
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return;
        }
        $active = User::query()->where('is_active', true)->whereIn('id', $ids)->pluck('id')->all();
        foreach ($active as $userId) {
            UserNotification::query()->create([
                'user_id' => (int) $userId,
                'type' => $type,
                'resource_type' => $resourceType,
                'resource_id' => $resourceId,
                'title' => $title,
                'count' => 1,
            ]);
        }
        $this->pingUsers($active);
    }

    /**
     * @param  list<int|string>  $ids
     */
    private function pingUsers(array $ids): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === []) {
            return;
        }

        $run = function () use ($ids): void {
            try {
                app(WebPushPing::class)->pingUsers($ids);
            } catch (\Throwable $e) {
                report($e);
            }
        };

        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            $run();

            return;
        }

        dispatch($run)->afterResponse();
    }

    private function safeTitle(string $title): string
    {
        $title = trim($title);
        $title = preg_replace('/\s+/u', ' ', $title) ?? $title;
        if (mb_strlen($title) > 80) {
            $title = mb_substr($title, 0, 77).'…';
        }

        return $title === '' ? 'чат' : $title;
    }

    private function clip(string $value, int $max): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        return mb_strlen($value) > $max ? mb_substr($value, 0, $max) : $value;
    }
}
