<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class WorkShift extends Model
{
    protected $fillable = [
        'user_id',
        'shift_date',
        'type',
        'route_id',
        'deviation_type',
        'started_at',
        'ended_at',
        'start_location',
        'end_location',
        'break_duration',
        'total_minutes',
    ];

    protected $casts = [
        'shift_date' => 'date',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function route() { return $this->belongsTo(RoutesCatalog::class, 'route_id'); }
    public function user() { return $this->belongsTo(User::class); }

    /**
     * Автоматический пересчет длительности смены перед сохранением в базу
     */
    protected static function booted()
    {
        static::saving(function ($shift) {
            // В будущем эти ставки будут динамически браться из профиля пользователя
            $rates = [
                'work' => 450.00,        // 100% линия
                'training' => 350.00,    // Повышение квалификации
                'sick' => 250.00,        // Больничный лист
                'study_leave' => 300.00, // Учебный отпуск
            ];

            // 1. Если это ОТВЛЕЧЕНИЕ (Больничный, учеба)
            if ($shift->type === 'deviation') {
                $rateKey = $shift->deviation_type;
                $hourlyRate = $rates[$rateKey] ?? 0;

                // Для отвлечений обычно забивают фиксированное стандартное время (например, 8 часов = 480 минут)
                $shift->total_minutes = $shift->total_minutes ?: 480;
                $shift->estimated_earnings = ($shift->total_minutes / 60) * $hourlyRate;
            }
            // 2. Если это РЕАЛЬНАЯ СМЕНА по маршруту
            else if ($shift->started_at && $shift->ended_at) {
                $start = Carbon::parse($shift->started_at);
                $end = Carbon::parse($shift->ended_at);

                // Считаем разницу, включая переход через полночь!
                $diffInMinutes = $start->diffInMinutes($end);
                $pureWorkMinutes = $diffInMinutes - (int)$shift->break_duration;

                $shift->total_minutes = $pureWorkMinutes > 0 ? $pureWorkMinutes : 0;
                $hourlyRate = $rates['work'];
                $shift->estimated_earnings = ($shift->total_minutes / 60) * $hourlyRate;
            }
        });
    }
}
