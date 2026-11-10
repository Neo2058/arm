<?php

namespace App\Console\Commands;

use App\Models\Naryad;
use App\Models\NaryadPersonFile;
use App\Services\ClickHouseService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

class CleanRetentionCommand extends Command
{
    protected $signature = 'arm:clean-retention
                            {--months=3 : Срок хранения нарядов на сервере в месяцах (по умолчанию 3)}
                            {--days= : Срок хранения в днях (если задан, имеет приоритет)}
                            {--dry-run : Тестовый прогон без реального удаления}';

    protected $description = 'Очистка устаревших копий нарядов и данных на сервере старше 3 месяцев для предотвращения засорения сервера';

    public function handle(): int
    {
        $days = $this->option('days') !== null ? (int) $this->option('days') : ((int) $this->option('months') * 30);
        if ($days <= 0) {
            $days = 90;
        }

        $cutoffDate = Carbon::now()->subDays($days)->startOfDay();
        $cutoffDateStr = $cutoffDate->format('Y-m-d');
        $isDryRun = (bool) $this->option('dry-run');

        $this->info("Очистка серверных копий нарядов и данных старше {$days} дней (до {$cutoffDateStr})...");

        // 1. Поиск устаревших опубликованных нарядов (модель Naryad)
        $oldNaryads = Naryad::query()
            ->whereDate('naryad_date', '<', $cutoffDateStr)
            ->with('personFiles')
            ->get();

        $deletedNaryadsCount = 0;
        $deletedPersonFilesCount = 0;
        $deletedS3FilesCount = 0;

        foreach ($oldNaryads as $naryad) {
            $this->line("Устаревший наряд id={$naryad->id}, дата={$naryad->naryad_date?->format('Y-m-d')}: {$naryad->title}");

            foreach ($naryad->personFiles as $pf) {
                $deletedPersonFilesCount++;
                if (! $isDryRun) {
                    try {
                        if (Storage::disk('s3')->exists($pf->file_path)) {
                            Storage::disk('s3')->delete($pf->file_path);
                            $deletedS3FilesCount++;
                        }
                    } catch (Throwable) {
                    }
                }
            }

            if (! $isDryRun) {
                try {
                    // Удаление основных файлов наряда из S3
                    if ($naryad->file_path && Storage::disk('s3')->exists($naryad->file_path)) {
                        Storage::disk('s3')->delete($naryad->file_path);
                        $deletedS3FilesCount++;
                    }
                    if (is_array($naryad->source_files)) {
                        foreach ($naryad->source_files as $sf) {
                            if ($sf && Storage::disk('s3')->exists($sf)) {
                                Storage::disk('s3')->delete($sf);
                                $deletedS3FilesCount++;
                            }
                        }
                    }
                } catch (Throwable) {
                }

                $naryad->personFiles()->delete();
                $naryad->delete();
            }

            $deletedNaryadsCount++;
        }

        // 2. Очистка устаревших копий из arm_published_naryads (если таблица существует)
        $deletedPublishedRows = 0;
        if (Schema::hasTable('arm_published_naryads')) {
            $query = DB::table('arm_published_naryads')->whereDate('naryad_date', '<', $cutoffDateStr);
            $deletedPublishedRows = $query->count();
            if (! $isDryRun && $deletedPublishedRows > 0) {
                $query->delete();
            }
        }

        // 3. Очистка устаревших назначений на сервере (оставляем только последние 90 дней для учёта)
        $deletedAssignments = 0;
        if (Schema::hasTable('arm_naryad_assignments')) {
            $q = DB::table('arm_naryad_assignments')->whereDate('assignment_date', '<', $cutoffDateStr);
            $deletedAssignments = $q->count();
            if (! $isDryRun && $deletedAssignments > 0) {
                $q->delete();
            }
        }

        if (Schema::hasTable('naryad_assignments')) {
            $q = DB::table('naryad_assignments')->whereDate('assignment_date', '<', $cutoffDateStr);
            $deletedAssignments += $q->count();
            if (! $isDryRun && $q->count() > 0) {
                $q->delete();
            }
        }

        $modeStr = $isDryRun ? ' [ТЕСТОВЫЙ ПРОГОН - DRY RUN]' : '';
        $this->info("Завершена очистка сервера{$modeStr}:");
        $this->info(" - Нарядов удалено с сервера: {$deletedNaryadsCount}");
        $this->info(" - Персональных файлов удалено: {$deletedPersonFilesCount}");
        $this->info(" - Файлов S3/MinIO очищено: {$deletedS3FilesCount}");
        $this->info(" - Записей arm_published_naryads удалено: {$deletedPublishedRows}");
        $this->info(" - Устаревших назначений удалено: {$deletedAssignments}");

        if (! $isDryRun && ($deletedNaryadsCount > 0 || $deletedPublishedRows > 0)) {
            try {
                ClickHouseService::log('server_retention_cleaned', 0, [
                    'days' => $days,
                    'cutoff_date' => $cutoffDateStr,
                    'naryads' => $deletedNaryadsCount,
                    's3_files' => $deletedS3FilesCount,
                ]);
            } catch (Throwable) {
            }
        }

        return self::SUCCESS;
    }
}
