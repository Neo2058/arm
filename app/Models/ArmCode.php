<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArmCode extends Model
{
    protected $fillable = [
        'book_code',
        'code',
        'name',
        'nom',
    ];

    public function book(): BelongsTo
    {
        return $this->belongsTo(ArmCodeBook::class, 'book_code', 'kodspr');
    }

    public function syncSpecialized(): void
    {
        $code = trim((string) $this->code);
        if ($code === '') {
            return;
        }
        $name = trim((string) $this->name) ?: $code;

        if ($this->book_code === '41') {
            ArmTariff::updateOrCreate(
                ['code' => $code],
                ['name' => $name, 'rate' => (float) $code]
            );

            return;
        }
        if ($this->book_code === '50') {
            ArmPayKind::updateOrCreate(
                ['code' => $code],
                ['name' => $name]
            );

            return;
        }
        if ($this->book_code === '52') {
            $existing = DeviationsCatalog::query()->where('short_code', $code)->first();
            if ($existing) {
                return;
            }
            DeviationsCatalog::create([
                'name' => $name,
                'sys_key' => 'nsi52_'.$code,
                'short_code' => $code,
                'hourly_rate' => 0,
                'default_minutes' => 480,
            ]);
        }
    }

    public function dropSpecialized(): void
    {
        $code = trim((string) $this->code);
        if ($code === '') {
            return;
        }
        if ($this->book_code === '41') {
            ArmTariff::query()->where('code', $code)->delete();
        }
        if ($this->book_code === '50') {
            ArmPayKind::query()->where('code', $code)->delete();
        }
    }
}
