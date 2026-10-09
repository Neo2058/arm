<?php

namespace App\Http\Controllers;

use App\Models\ActionLog;
use App\Models\InstructorShiftTable;
use App\Models\Naryad;
use App\Models\NaryadPersonFile;
use App\Services\FileProxyService;
use App\Services\InstructorNaryad\NaryadDocumentLoader;
use App\Services\InstructorNaryad\SearchEngine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class NaryadViewerController extends Controller
{
    public function __construct(private FileProxyService $files) {}

    public function index()
    {
        $user = auth()->user();

        $query = $user->constrainByAllowedRoles(Naryad::query());

        $personFiles = NaryadPersonFile::query()
            ->where('user_id', $user->id)
            ->get()
            ->keyBy('naryad_id');

        $naryads = $query->orderBy('naryad_date', 'desc')
            ->get()
            ->map(function (Naryad $naryad) use ($personFiles) {
                $mine = $personFiles->get($naryad->id);
                $personalText = '';
                if ($mine && Storage::disk('s3')->exists($mine->file_path)) {
                    $personalText = (string) Storage::disk('s3')->get($mine->file_path);
                }

                return [
                    'id' => $naryad->id,
                    'title' => $naryad->title,
                    'naryad_date' => $naryad->naryad_date?->format('d.m.Y'),
                    'naryad_date_iso' => $naryad->naryad_date?->format('Y-m-d'),
                    'has_pdf' => $naryad->hasGeneralPdf(),
                    'personal_name' => $mine?->file_name,
                    'personal_text' => $personalText,
                    'personal_url' => $mine ? route('naryady.mine', $naryad) : null,
                    'pdf_url' => $naryad->hasGeneralPdf() ? route('naryady.pdf', $naryad) : null,
                ];
            });

        $isAdmin = $user->isAdmin();

        ActionLog::log('view_naryads_page');

        return view('naryady.index', [
            'naryads' => $naryads,
            'isAdmin' => $isAdmin,
            'userFileName' => $user->name,
        ]);
    }

    public function show(Naryad $naryad)
    {
        $user = auth()->user();

        if (! $user->canAccessByRoles($naryad->allowed_roles)) {
            abort(403, 'Доступ ограничен.');
        }

        if (! $naryad->hasGeneralPdf()) {
            abort(404, 'Общий PDF для этой выкладки не загружен.');
        }

        ActionLog::log('view_naryad', [
            'naryad_id' => $naryad->id,
            'title' => $naryad->title,
            'date' => $naryad->naryad_date?->format('Y-m-d'),
        ]);

        return response()->json([
            'url' => route('naryady.pdf', $naryad),
            'title' => $naryad->title,
            'naryad_date' => $naryad->naryad_date?->format('d.m.Y'),
        ]);
    }

    public function pdf(Naryad $naryad)
    {
        $user = auth()->user();
        if (! $user->canAccessByRoles($naryad->allowed_roles) || ! $naryad->hasGeneralPdf()) {
            abort(403, 'Доступ ограничен.');
        }
        if (! $this->files->exists($naryad->file_path)) {
            abort(404, 'Файл не найден');
        }

        $filename = pathinfo($naryad->file_path, PATHINFO_FILENAME).'.pdf';

        return $this->files->respond($naryad->file_path, $filename, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.addslashes($filename).'"',
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function mine(Naryad $naryad)
    {
        $user = auth()->user();
        if (! $user->canAccessByRoles($naryad->allowed_roles)) {
            abort(403, 'Доступ ограничен.');
        }

        $file = NaryadPersonFile::query()
            ->where('naryad_id', $naryad->id)
            ->where('user_id', $user->id)
            ->first();

        if (! $file || ! Storage::disk('s3')->exists($file->file_path)) {
            abort(404, 'Личный файл наряда не найден.');
        }

        $filename = $file->file_name ?: $user->name.'.txt';

        return Storage::disk('s3')->response($file->file_path, $filename, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => 'inline; filename="'.addslashes($filename).'"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function search(Request $request, Naryad $naryad)
    {
        if (! auth()->user()->canAccessByRoles($naryad->allowed_roles) || ! $naryad->hasGeneralPdf()) {
            abort(403, 'Доступ ограничен.');
        }

        return response()->json([
            'url' => route('naryady.pdf', $naryad),
            'title' => $naryad->title,
            'search_term' => $request->input('q'),
        ]);
    }

    public function searchPeople(Request $request)
    {
        $data = $request->validate([
            'naryad_ids' => 'required|array|min:1',
            'naryad_ids.*' => 'integer',
            'queries' => 'required|array|min:1',
            'queries.*' => 'string|max:200',
        ]);

        $user = auth()->user();
        $naryadModels = Naryad::query()
            ->whereIn('id', $data['naryad_ids'])
            ->get();

        $parsed = [];
        $errors = [];
        foreach ($naryadModels as $naryad) {
            if (! $user->canAccessByRoles($naryad->allowed_roles)) {
                continue;
            }
            $paths = $naryad->sourceFilesForParse();
            if ($paths === []) {
                $errors[] = $naryad->title.': нет TXT-нарядов для поиска.';
                continue;
            }
            foreach ($paths as $path) {
                try {
                    if (! Storage::disk('s3')->exists($path)) {
                        $errors[] = $naryad->title.': файл наряда не найден.';
                        continue;
                    }
                    $bytes = Storage::disk('s3')->get($path);
                    if (! is_string($bytes) || $bytes === '') {
                        $errors[] = $naryad->title.': пустой файл наряда.';
                        continue;
                    }
                    $utf8 = NaryadDocumentLoader::utf8FromContents($bytes, $path);
                    $doc = NaryadDocumentLoader::parseUtf8($utf8, $naryad->title ?: $path);
                    if ($doc['assignments'] === []) {
                        $errors[] = $naryad->title.': в файле нет назначений (нужен текст наряда, не скан).';
                    }
                    $parsed[] = $doc;
                } catch (\Throwable $e) {
                    $errors[] = $naryad->title.': '.$e->getMessage();
                }
            }
        }

        if ($parsed === []) {
            return response()->json([
                'message' => 'Не удалось разобрать выбранные наряды.',
                'errors' => $errors,
                'text' => '',
                'hits' => 0,
                'missing' => [],
            ], 422);
        }

        $work = [];
        $weekend = [];
        if ($user->isInstructor() || $user->isAdmin()) {
            foreach (InstructorShiftTable::where('user_id', $user->id)->get() as $table) {
                $entries = $table->entries ?? [];
                if ($table->kind === 'weekend') {
                    $weekend = array_merge($weekend, $entries);
                } else {
                    $work = array_merge($work, $entries);
                }
            }
        }

        $queries = [];
        foreach ($data['queries'] as $q) {
            $t = trim((string) $q);
            if ($t !== '') {
                $queries[] = $t;
            }
        }
        if ($queries === []) {
            return response()->json([
                'message' => 'Нужен хотя бы один запрос ФИО.',
                'errors' => $errors,
                'text' => '',
                'hits' => 0,
                'missing' => [],
            ], 422);
        }

        $result = SearchEngine::run($parsed, $queries, $work, $weekend);

        ActionLog::log('batch_naryad_search', [
            'naryads' => count($parsed),
            'queries' => count($queries),
            'hits' => count($result['hits']),
        ]);

        return response()->json([
            'text' => $result['text'],
            'hits' => count($result['hits']),
            'missing' => $result['missing'],
            'errors' => $errors,
        ]);
    }
}
