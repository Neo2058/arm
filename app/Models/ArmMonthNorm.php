<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ArmMonthNorm extends Model
{
    protected $fillable = [
        'year_month',
        'month_hours',
        'day_hours',
    ];

    protected $casts = [
        'month_hours' => 'float',
        'day_hours' => 'float',
    ];

    public static function fromMesgod(string $mesgod): ?string
    {
        $s = trim($mesgod);
        if (preg_match('/^(\d{2})\.(\d{4})$/', $s, $m)) {
            return $m[2].'-'.$m[1];
        }

        return self::fromGodmes($s);
    }

    public static function fromGodmes(string $godmes): ?string
    {
        $s = trim($godmes);
        if (preg_match('/^(\d{4})(\d{2})$/', $s, $m) && (int) $m[2] >= 1 && (int) $m[2] <= 12) {
            return $m[1].'-'.$m[2];
        }

        return null;
    }

    public static function toGodmes(string $yearMonth): string
    {
        return str_replace('-', '', $yearMonth);
    }
}
