<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdminNotification extends Model
{
    protected $fillable = [
        'type',
        'title',
        'message',
        'data',
        'is_read',
    ];

    protected $casts = [
        'data' => 'array',
        'is_read' => 'boolean',
    ];

    public function markAsRead(): void
    {
        $this->update(['is_read' => true]);
    }

    public static function unreadCount(): int
    {
        return static::where('is_read', false)->count();
    }
}
