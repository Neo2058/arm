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
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class NaryadIssuePublisher
{
    /**
     * Prepare immutable personal files before switching database references.
     * Existing objects are never overwritten or deleted, including on rollback.
     */
    public function publish(Naryad $naryad): int
    {
        return DB::transaction(function () use ($naryad): int {
            // Serialize publishers of one issue and use its current source metadata.
            $current = Naryad::query()->lockForUpdate()->findOrFail($naryad->getKey());
            $docs = $this->loadNaryads($current);
            if ($docs === []) {
                return 0;
            }

            $work = $this->loadShiftEntries($current->work_shifts_path, 'work');
            $weekend = $this->loadShiftEntries($current->weekend_shifts_path, 'weekend');
            $users = User::query()
                ->where('is_active', true)
                ->with(['personnel', 'profile'])
                ->orderBy('id')
                ->get();

            $revision = (string) Str::uuid();
            $disk = Storage::disk('s3');
            $newRecords = [];

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
                $path = 'naryads/'.$current->id.'/people/'.$revision.'/'.$user->id.'.txt';

                if ($disk->put($path, $result['text']) !== true) {
                    throw new RuntimeException('Не удалось записать новую выдачу наряда. Предыдущая выдача сохранена.');
                }
                $stored = $disk->get($path);
                if (! is_string($stored) || ! hash_equals(hash('sha256', $result['text']), hash('sha256', $stored))) {
                    throw new RuntimeException('Проверка записанного файла наряда не пройдена. Предыдущая выдача сохранена.');
                }

                $newRecords[] = [
                    'naryad_id' => $current->id,
                    'user_id' => $user->id,
                    'fio' => $fio,
                    'file_name' => $fio.'.txt',
                    'file_path' => $path,
                    'hits_count' => $hits,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            // An empty parse/match is not permission to clear an existing issue.
            if ($newRecords === []) {
                return 0;
            }

            $current->personFiles()->delete();
            NaryadPersonFile::insert($newRecords);
            $created = count($newRecords);

            // Also respect any outer transaction (e.g. an administrative edit).
            DB::afterCommit(function () use ($current, $docs, $created): void {
                try {
                    ClickHouseService::log('naryad_issue_published', $current->id, [
                        'sources' => count($docs),
                        'person_files' => $created,
                    ]);
                } catch (Throwable) {
                }
            });

            // Unreferenced revision files are retained: deletion needs its own policy.
            return $created;
        });
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
            $bytes = Storage::disk('s3')->get($path);
            if (! is_string($bytes) || trim($bytes) === '') {
                throw new RuntimeException('Не удалось прочитать один из исходников наряда. Предыдущая выдача сохранена.');
            }
            $utf8 = NaryadDocumentLoader::utf8FromContents($bytes, $path);
            $doc = NaryadDocumentLoader::parseUtf8($utf8, basename($path));
            if ($doc['assignments'] === []) {
                throw new RuntimeException('В одном из исходников не распознаны назначения. Предыдущая выдача сохранена.');
            }
            $docs[] = $doc;
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
}
