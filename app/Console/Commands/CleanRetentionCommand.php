<?php

namespace App\Console\Commands;

use App\Models\Naryad;
use App\Models\NaryadPersonFile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CleanRetentionCommand extends Command
{
    protected $signature = 'arm:clean-retention
                            {--months=3 : Порог отчёта: количество периодов по 30 дней}
                            {--days= : Порог отчёта в днях, имеет приоритет}
                            {--dry-run : Только отчёт; обязательный режим, удаление отключено}';

    protected $description = 'Ручной отчёт о старых нарядах без изменения БД и файлов; удаление отключено';

    public function handle(): int
    {
        if (! $this->option('dry-run')) {
            $this->error('Удаление нарядов, назначений и файлов отключено. Для отчёта используйте --dry-run.');

            return self::FAILURE;
        }

        $value = $this->option('days') ?? $this->option('months');
        if (! is_string($value) || ! ctype_digit($value) || (int) $value < 1 || (int) $value > 36500) {
            $this->error('Порог должен быть положительным целым числом не более 36500.');

            return self::FAILURE;
        }
        $days = (int) $value * ($this->option('days') === null ? 30 : 1);
        if ($days > 36500) {
            $this->error('Порог отчёта не должен превышать 36500 дней.');

            return self::FAILURE;
        }
        $cutoff = now()->subDays($days)->startOfDay()->toDateString();
        $old = Naryad::query()->whereDate('naryad_date', '<', $cutoff);
        $personal = NaryadPersonFile::query()->whereIn('naryad_id', (clone $old)->select('id'))->count();

        $this->info("Отчёт без удаления: записи до {$cutoff} (старше {$days} дней).");
        $this->line('Наряды: '.$old->count());
        $this->line('Персональные записи: '.$personal);
        foreach (['arm_published_naryads' => 'naryad_date', 'arm_naryad_assignments' => 'assignment_date', 'naryad_assignments' => 'plan_date'] as $table => $date) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, $date)) {
                $this->line($table.': '.DB::table($table)->whereDate($date, '<', $cutoff)->count());
            }
        }
        $this->info('БД и MinIO не изменены. Файловое хранилище не опрашивалось.');

        return self::SUCCESS;
    }
}
