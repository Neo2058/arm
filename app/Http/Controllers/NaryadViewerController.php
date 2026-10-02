<?php

namespace App\Http\Controllers;

use App\Models\ActionLog;
use App\Models\InstructorShiftTable;
use App\Models\Naryad;
use App\Services\InstructorNaryad\NaryadDocumentLoader;
use App\Services\InstructorNaryad\SearchEngine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class NaryadViewerController extends Controller
{
    public function index()
    {
        $user = auth()->user();

        $query = $user->constrainByAllowedRoles(Naryad::query());

        $naryads = $query->orderBy('naryad_date', 'desc')
            ->get()
            ->map(function ($naryad) {
                return [
                    'id' => $naryad->id,
                    'title' => $naryad->title,
                    'naryad_date' => $naryad->naryad_date?->format('d.m.Y'),
                    'naryad_date_iso' => $naryad->naryad_date?->format('Y-m-d'), // for client filtering
                ];
            });

        $isAdmin = $user->isAdmin();

        // Log viewing the naryads list page (the three blocks viewer)
        // Hybrid: goes to relational DB + ClickHouse
        ActionLog::log('view_naryads_page');

        return view('naryady.index', [
            'naryads' => $naryads,
            'isAdmin' => $isAdmin,
        ]);
    }

    public function show(Naryad $naryad)
    {
        $user = auth()->user();

        // Role check
        if (! $user->canAccessByRoles($naryad->allowed_roles)) {
            abort(403, 'Доступ ограничен.');
        }

        $url = (function () use ($naryad) {
            try {
                return Storage::disk('s3')->temporaryUrl(
                    $naryad->file_path,
                    now()->addMinutes(30),
                    ['ResponseContentDisposition' => 'inline']
                );
            } catch (\Throwable $e) {
                \Log::warning('S3 temp url failed for naryad', ['id' => $naryad->id, 'err' => $e->getMessage()]);

                return null;
            }
        })();

        // Log the PDF view action
        // Hybrid: goes to relational DB + ClickHouse via ActionLog::log()
        ActionLog::log('view_naryad', [
            'naryad_id' => $naryad->id,
            'title' => $naryad->title,
            'date' => $naryad->naryad_date?->format('Y-m-d'),
        ]);

        return response()->json([
            'url' => $url,
            'title' => $naryad->title,
            'naryad_date' => $naryad->naryad_date?->format('d.m.Y'),
        ]);
    }

    public function search(Request $request, Naryad $naryad)
    {
        $url = (function () use ($naryad) {
            try {
                return Storage::disk('s3')->temporaryUrl(
                    $naryad->file_path,
                    now()->addMinutes(30),
                    ['ResponseContentDisposition' => 'inline']
                );
            } catch (\Throwable $e) {
                \Log::warning('S3 temp url failed for naryad', ['id' => $naryad->id, 'err' => $e->getMessage()]);

                return null;
            }
        })();

        return response()->json([
            'url' => $url,
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
            try {
                if (! Storage::disk('s3')->exists($naryad->file_path)) {
                    $errors[] = $naryad->title.': файл наряда не найден.';
                    continue;
                }
                $bytes = Storage::disk('s3')->get($naryad->file_path);
                if (! is_string($bytes) || $bytes === '') {
                    $errors[] = $naryad->title.': пустой файл наряда.';
                    continue;
                }
                $utf8 = NaryadDocumentLoader::utf8FromContents($bytes, $naryad->file_path);
                $doc = NaryadDocumentLoader::parseUtf8($utf8, $naryad->title ?: $naryad->file_path);
                if ($doc['assignments'] === []) {
                    $errors[] = $naryad->title.': в файле нет назначений (нужен текст наряда, не скан).';
                }
                $parsed[] = $doc;
            } catch (\Throwable $e) {
                $errors[] = $naryad->title.': '.$e->getMessage();
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
