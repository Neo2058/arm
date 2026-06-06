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
        'deviation_id',
        'started_at',
        'ended_at',
        'start_location',
        'end_location',
        'break_duration',
        'total_minutes',
        'estimated_earnings',
    ];

    protected $casts = [
        'shift_date' => 'date',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function route() { return $this->belongsTo(RoutesCatalog::class, 'route_id'); }
    public function deviation() { return $this->belongsTo(DeviationsCatalog::class, 'deviation_id'); }
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

            // 1. Если машинист выбрал ОТВЛЕЧЕНИЕ (Больничный, учеба, медкомиссия и т.д.)
            if ($shift->type === 'deviation' && $shift->deviation_id) {
                // Подгружаем объект отвлечения из каталога для получения ЖИВОЙ тарифной ставки
                $deviationTemplate = DeviationsCatalog::find($shift->deviation_id);

                if ($deviationTemplate) {
                    $shift->total_minutes = $shift->total_minutes ?: $deviationTemplate->default_minutes;
                    $shift->estimated_earnings = ($shift->total_minutes / 60) * $deviationTemplate->hourly_rate;
                }
            }
            // 2. Если это РЕАЛЬНАЯ СМЕНА по маршруту локомотива
            else if ($shift->type === 'work' && $shift->started_at && $shift->ended_at) {
                $start = Carbon::parse($shift->started_at);
                $end = Carbon::parse($shift->ended_at);

                $diffInMinutes = $start->diffInMinutes($end);
                $pureWorkMinutes = $diffInMinutes - (int)$shift->break_duration;

                $shift->total_minutes = $pureWorkMinutes > 0 ? $pureWorkMinutes : 0;
                $hourlyRate = 450.00; // Твой дефолтный тариф за линию
                $shift->estimated_earnings = ($shift->total_minutes / 60) * $hourlyRate;
            }
        });
    }
}
