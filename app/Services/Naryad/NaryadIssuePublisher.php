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
use Illuminate\Support\Facades\Storage;
use Throwable;

class NaryadIssuePublisher
{
    /**
     * Разбор TXT-нарядов и разбивки смен (ядро searching) → личный файл на каждого найденного пользователя.
     */
    public function publish(Naryad $naryad): int
    {
        $docs = $this->loadNaryads($naryad);
        $this->deletePersonFiles($naryad);
        if ($docs === []) {
            return 0;
        }

        $work = $this->loadShiftEntries($naryad->work_shifts_path, 'work');
        $weekend = $this->loadShiftEntries($naryad->weekend_shifts_path, 'weekend');
        $created = 0;

        $users = User::query()
            ->where('is_active', true)
            ->with(['personnel', 'profile'])
            ->orderBy('id')
            ->get();

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
            Storage::disk('s3')->put($path, $result['text']);
            NaryadPersonFile::create([
                'naryad_id' => $naryad->id,
                'user_id' => $user->id,
                'fio' => $fio,
                'file_name' => $fileName,
                'file_path' => $path,
                'hits_count' => $hits,
            ]);
            $created++;
        }

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
