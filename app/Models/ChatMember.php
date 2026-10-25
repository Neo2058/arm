<?php

namespace App\Models;

use App\Enums\ChatMemberRole;
use App\Enums\ChatMemberSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatMember extends Model
{
    protected $guarded = [];

    protected $casts = [
        'role' => ChatMemberRole::class,
        'source' => ChatMemberSource::class,
        'window_starts_on' => 'date',
        'window_ends_on' => 'date',
        'last_read_at' => 'datetime',
    ];

    public function room(): BelongsTo
    {
        return $this->belongsTo(ChatRoom::class, 'room_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }
}
