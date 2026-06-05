<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserDevice extends Model
{
    // Разрешаем запись этих полей из формы заявки
    protected $fillable = [
        'user_id',
        'device_key',
        'device_name',
        'is_approved',
    ];

    // Указываем, что тип поля is_approved — логический (true/false)
    protected $casts = [
        'is_approved' => 'boolean',
    ];

    /**
     * Связь: устройство принадлежит конкретному пользователю
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
