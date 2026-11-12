<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Services\DocumentPublishing;
use Illuminate\Console\Command;

class IndexDocumentHashes extends Command
{
    protected $signature = 'documents:index-hashes {--apply : Прочитать существующие файлы и заполнить SHA-256 в БД}';

    protected $description = 'Проверить покрытие каталога SHA-256; файлы не изменяются и не удаляются';

    public function handle(DocumentPublishing $publishing): int
    {
        $this->info('Не проиндексировано: '.Document::whereNull('file_sha256')->count());
        if ($this->option('apply')) {
            try {
                $publishing->indexLegacy();
            } catch (\Throwable $error) {
                report($error);
                $this->error('Индексация не завершена: проверьте доступность файлов. Файлы не удалялись.');

                return self::FAILURE;
            }
        }
        $duplicates = Document::select('file_sha256')->whereNotNull('file_sha256')->groupBy('file_sha256')->havingRaw('COUNT(*) > 1')->get()->count();
        $this->info('Групп одинаковых файлов среди проиндексированных: '.$duplicates);
        $this->info('Автоматического удаления или объединения карточек нет.');

        return self::SUCCESS;
    }
}
