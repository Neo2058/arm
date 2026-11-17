<?php

namespace App\Models;

use App\Enums\UserNotificationType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserNotification extends Model
{
    protected $guarded = [];

    protected $casts = [
        'type' => UserNotificationType::class,
        'read_at' => 'datetime',
        'count' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $type = $this->type instanceof UserNotificationType ? $this->type->value : (string) $this->type;

        return [
            'id' => $this->id,
            'type' => $type,
            'title' => $this->displayTitle(),
            'count' => (int) $this->count,
            'resource_type' => $this->resource_type,
            'resource_id' => $this->resource_id === null ? null : (int) $this->resource_id,
            'href' => $this->href(),
            'read_at' => $this->read_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'created_at' => $this->created_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'updated_at' => $this->updated_at?->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    public function displayTitle(): string
    {
        $title = (string) $this->title;
        $count = (int) $this->count;
        if ($count > 1 && $this->type === UserNotificationType::ChatMessage) {
            return $title.' ('.$count.')';
        }

        return $title;
    }

    public function href(): ?string
    {
        return match ($this->type) {
            UserNotificationType::FormularEntry => '/formular?review=1',
            UserNotificationType::FormularReview => '/formular',
            UserNotificationType::InstructorNotice => '/discipline',
            default => '/notifications',
        };
    }
}
