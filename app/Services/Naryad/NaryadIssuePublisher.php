<?php

namespace App\Services\Naryad;

use App\Models\Naryad;
use App\Models\NaryadPersonFile;
use App\Models\User;
use App\Services\ClickHouseService;
use App\Services\InstructorNaryad\BreakdownPdfExtractor;
use App\Services\InstructorNaryad\FioParser;
use App\Services\InstructorNaryad\NaryadDocumentLoader;
use App\Services\InstructorNaryad\SearchEngine;
use App\Services\InstructorNaryad\ShiftCatalog;
use App\Services\InstructorNaryad\Text;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class NaryadIssuePublisher
{
    /**
     * Разбор TXT-нарядов и разбивки смен (ядро searching) → личный файл на каждого найденного пользователя.
     * Безопасная публикация: сначала подготавливаются и проверяются новые файлы, затем атомарно
     * обновляются записи в БД, и только после успешного коммита удаляются старые файлы.
     */
    public function publish(Naryad $naryad): int
    {
        $docs = $this->loadNaryads($naryad);
        if ($docs === []) {
            return 0;
        }

        $work = $this->loadShiftEntries($naryad->work_shifts_path, 'work');
        $weekend = $this->loadShiftEntries($naryad->weekend_shifts_path, 'weekend');

        $users = User::query()
            ->where('is_active', true)
            ->with(['personnel', 'profile'])
            ->orderBy('id')
            ->get();

        $newRecords = [];
        $writtenPaths = [];

        try {
            foreach ($users as $user) {
                $query = $this->queryForUser($user);
                if ($query === '') {
                    continue;
                }
                $result = SearchEngine::run($docs, [$query], $work, $weekend);
                $hits = count($result['hits']);
                if ($hits === 0) {
                    continue;
                }
                $fio = FioParser::label(FioParser::parseQuery($query));
                $fileName = $fio.'.txt';
                $path = 'naryads/'.$naryad->id.'/people/'.$user->id.'.txt';

                $putOk = Storage::disk('s3')->put($path, $result['text']);
                if ($putOk === false) {
                    throw new RuntimeException("Не удалось сохранить персональный файл в S3: {$path}");
                }
                $writtenPaths[] = $path;

                $newRecords[] = [
                    'naryad_id' => $naryad->id,
                    'user_id' => $user->id,
                    'fio' => $fio,
                    'file_name' => $fileName,
                    'file_path' => $path,
                    'hits_count' => $hits,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            // Получаем список старых записей до их замены
            $oldFiles = $naryad->personFiles()->get();
            $oldPaths = $oldFiles->pluck('file_path')->all();

            // Атомарное обновление записей в БД
            DB::transaction(function () use ($naryad, $newRecords) {
                $naryad->personFiles()->delete();
                if ($newRecords !== []) {
                    NaryadPersonFile::insert($newRecords);
                }
            });

            // Безопасная очистка старых файлов, которые больше не используются
            $newPathsSet = array_flip($writtenPaths);
            foreach ($oldPaths as $oldPath) {
                if (!isset($newPathsSet[$oldPath])) {
                    Storage::disk('s3')->delete($oldPath);
                }
            }
        } catch (Throwable $e) {
            // При ошибке во время записи очищаем новые частично созданные файлы
            foreach ($writtenPaths as $p) {
                try {
                    Storage::disk('s3')->delete($p);
                } catch (Throwable) {
                }
            }
            throw $e;
        }

        $created = count($newRecords);

        try {
            ClickHouseService::log('naryad_issue_published', $naryad->id, [
                'sources' => count($docs),
                'person_files' => $created,
            ]);
        } catch (Throwable) {
        }

        return $created;
    }

    public function queryForUser(User $user): string
    {
        return FioParser::queryFromPerson($user->personnel?->full_name ?: $user->name);
    }

    /**
     * @return list<array{label: string, meta: array, assignments: list<array>}>
     */
    private function loadNaryads(Naryad $naryad): array
    {
        $docs = [];
        foreach ($naryad->sourceFilesForParse() as $path) {
            try {
                if (! Storage::disk('s3')->exists($path)) {
                    continue;
                }
                $bytes = Storage::disk('s3')->get($path);
                if (! is_string($bytes) || $bytes === '') {
                    continue;
                }
                $utf8 = NaryadDocumentLoader::utf8FromContents($bytes, $path);
                $docs[] = NaryadDocumentLoader::parseUtf8($utf8, basename($path));
            } catch (Throwable) {
                continue;
            }
        }

        return $docs;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadShiftEntries(?string $path, string $fallbackKind): array
    {
        if (! filled($path) || ! Storage::disk('s3')->exists($path)) {
            return ShiftCatalog::loadDefault($fallbackKind);
        }
        $bytes = Storage::disk('s3')->get($path);
        if (! is_string($bytes) || $bytes === '') {
            return ShiftCatalog::loadDefault($fallbackKind);
        }
        $original = basename($path);
        if (NaryadDocumentLoader::looksLikePdf($bytes, $original)) {
            $tmp = tempnam(sys_get_temp_dir(), 'naryadshift');
            if ($tmp === false) {
                return [];
            }
            $pdfPath = $tmp.'.pdf';
            @unlink($tmp);
            file_put_contents($pdfPath, $bytes);
            try {
                $utf8 = BreakdownPdfExtractor::extract($pdfPath, BreakdownPdfExtractor::inferKindFromName($original) ?? 'auto');
            } catch (Throwable) {
                @unlink($pdfPath);

                return [];
            }
            @unlink($pdfPath);
        } else {
            $utf8 = Text::toUtf8Auto($bytes);
        }

        return ShiftCatalog::loadTable($utf8);
    }

    private function deletePersonFiles(Naryad $naryad): void
    {
        foreach ($naryad->personFiles()->get() as $file) {
            Storage::disk('s3')->delete($file->file_path);
            $file->delete();
        }
    }
}
