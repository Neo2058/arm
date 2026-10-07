<?php

namespace App\Console\Commands;

use App\Services\Arm\LsbuhExportService;
use Illuminate\Console\Command;

class ArmExportLsbuh extends Command
{
    protected $signature = 'arm:export-lsbuh
                            {month : Месяц YYYY-MM}
                            {path? : Файл или каталог (по умолчанию LSBUH.DBF в текущем каталоге)}
                            {--kind= : primary|repeat, иначе оба}';

    protected $description = 'Выгрузка LSBUH.DBF (CP866) для бухгалтерии, как PODG_LSH';

    public function handle(LsbuhExportService $export): int
    {
        $month = (string) $this->argument('month');
        if (! preg_match('/^\d{4}-\d{2}$/', $month)) {
            $this->error('Месяц должен быть YYYY-MM.');

            return self::FAILURE;
        }
        $kind = $this->option('kind');
        $kind = is_string($kind) && in_array($kind, ['primary', 'repeat'], true) ? $kind : null;
        $path = $this->resolvePath($this->argument('path'));
        $count = $export->write($path, $month, $kind);
        $this->info("Записано {$count} строк → {$path}");

        return self::SUCCESS;
    }

    private function resolvePath(mixed $path): string
    {
        $path = is_string($path) && $path !== '' ? $path : 'LSBUH.DBF';
        if (is_dir($path) || str_ends_with($path, DIRECTORY_SEPARATOR)) {
            return rtrim($path, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'LSBUH.DBF';
        }

        return $path;
    }
}
