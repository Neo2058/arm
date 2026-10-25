<?php

namespace App\Models;

use App\Enums\ChatMessageStatus;
use App\Enums\ChatMessageType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ChatMessage extends Model
{
    protected $guarded = [];

    protected $casts = [
        'type' => ChatMessageType::class,
        'status' => ChatMessageStatus::class,
        'deleted_at' => 'datetime',
    ];

    public function room(): BelongsTo
    {
        return $this->belongsTo(ChatRoom::class, 'room_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    public function attachment(): HasOne
    {
        return $this->hasOne(ChatAttachment::class, 'message_id');
    }
}
