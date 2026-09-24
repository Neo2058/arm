<?php

namespace App\Http\Controllers\Instructor;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureInstructor;
use App\Models\InstructorNaryadFile;
use App\Models\InstructorQueryList;
use App\Models\InstructorShiftTable;
use App\Services\ClickHouseService;
use App\Services\InstructorNaryad\BreakdownPdfExtractor;
use App\Services\InstructorNaryad\NaryadParser;
use App\Services\InstructorNaryad\SearchEngine;
use App\Services\InstructorNaryad\ShiftCatalog;
use App\Services\InstructorNaryad\Text;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class NaryadSearchController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [EnsureInstructor::class];
    }

    public function index()
    {
        $user = Auth::user();

        return view('journal.naryad-search', [
            'naryads' => InstructorNaryadFile::where('user_id', $user->id)->latest()->get(),
            'shifts' => InstructorShiftTable::where('user_id', $user->id)->latest()->get(),
            'queries' => InstructorQueryList::where('user_id', $user->id)->latest()->get(),
            'result' => session('instructor_naryad_result'),
        ]);
    }

    public function uploadNaryads(Request $request)
    {
        $request->validate([
            'naryads' => 'required|array',
            'naryads.*' => 'file|max:20480',
        ]);

        $user = Auth::user();
        $count = 0;
        foreach ($request->file('naryads', []) as $file) {
            $path = $file->store('instructor-naryads/'.$user->id.'/naryads', 'local');
            $utf8 = Text::toUtf8Auto(Storage::disk('local')->get($path));
            $parsed = NaryadParser::parse($utf8);
            $meta = $parsed['meta'];
            $date = null;
            if ($meta['ok']) {
                $date = sprintf('%04d-%02d-%02d', $meta['year'], $meta['month'], $meta['day']);
            }
            InstructorNaryadFile::create([
                'user_id' => $user->id,
                'original_name' => $file->getClientOriginalName(),
                'path' => $path,
                'naryad_date' => $date,
                'weekday' => $meta['weekday'] ?: null,
                'weekend' => $meta['weekend'],
                'even_day' => $meta['even_day'],
                'meta' => $meta,
                'assignments' => $parsed['assignments'],
            ]);
            $count++;
        }

        return back()->with('success', "Загружено нарядов: {$count}");
    }

    public function destroyNaryad(InstructorNaryadFile $naryadFile)
    {
        $this->authorizeFile($naryadFile->user_id);
        Storage::disk('local')->delete($naryadFile->path);
        $naryadFile->delete();

        return back()->with('success', 'Наряд удалён.');
    }

    public function uploadShifts(Request $request)
    {
        $request->validate([
            'shifts' => 'required|array',
            'shifts.*' => 'file|max:20480',
            'kind' => 'nullable|in:work,weekend,auto',
        ]);

        $user = Auth::user();
        $kindPref = $request->input('kind', 'auto');
        $count = 0;
        foreach ($request->file('shifts', []) as $file) {
            $path = $file->store('instructor-naryads/'.$user->id.'/shifts', 'local');
            $absolute = Storage::disk('local')->path($path);
            $original = $file->getClientOriginalName();
            try {
                if (BreakdownPdfExtractor::isPdf($absolute, $original)) {
                    $kindHint = $kindPref === 'auto'
                        ? (BreakdownPdfExtractor::inferKindFromName($original) ?? 'auto')
                        : $kindPref;
                    $utf8 = BreakdownPdfExtractor::extract($absolute, $kindHint);
                } else {
                    $utf8 = Text::toUtf8Auto(Storage::disk('local')->get($path));
                }
            } catch (\Throwable $e) {
                Storage::disk('local')->delete($path);

                return back()->withErrors(['shifts' => $original.': '.$e->getMessage()]);
            }
            $kind = $kindPref === 'auto'
                ? (ShiftCatalog::detectKind($utf8)
                    ?? BreakdownPdfExtractor::inferKindFromName($original)
                    ?? 'work')
                : $kindPref;
            InstructorShiftTable::create([
                'user_id' => $user->id,
                'kind' => $kind,
                'original_name' => $file->getClientOriginalName(),
                'path' => $path,
                'entries' => ShiftCatalog::loadTable($utf8),
            ]);
            $count++;
        }

        return back()->with('success', "Загружено таблиц разбивки: {$count}");
    }

    public function destroyShift(InstructorShiftTable $shiftTable)
    {
        $this->authorizeFile($shiftTable->user_id);
        Storage::disk('local')->delete($shiftTable->path);
        $shiftTable->delete();

        return back()->with('success', 'Разбивка удалена.');
    }

    public function uploadQueries(Request $request)
    {
        $request->validate([
            'queries' => 'required|array',
            'queries.*' => 'file|max:2048',
        ]);

        $user = Auth::user();
        $count = 0;
        foreach ($request->file('queries', []) as $file) {
            $path = $file->store('instructor-naryads/'.$user->id.'/queries', 'local');
            $utf8 = Text::toUtf8Auto(Storage::disk('local')->get($path));
            $lines = [];
            foreach (Text::splitLines($utf8) as $line) {
                $t = Text::trim($line);
                if ($t !== '') {
                    $lines[] = $t;
                }
            }
            InstructorQueryList::create([
                'user_id' => $user->id,
                'original_name' => $file->getClientOriginalName(),
                'path' => $path,
                'queries' => $lines,
            ]);
            $count++;
        }

        return back()->with('success', "Загружено списков ФИО: {$count}");
    }

    public function destroyQuery(InstructorQueryList $queryList)
    {
        $this->authorizeFile($queryList->user_id);
        Storage::disk('local')->delete($queryList->path);
        $queryList->delete();

        return back()->with('success', 'Список ФИО удалён.');
    }

    public function run(Request $request)
    {
        $user = Auth::user();
        $naryadIds = $request->input('naryad_ids', []);
        $queryIds = $request->input('query_ids', []);
        $shiftIds = $request->input('shift_ids', []);

        $naryadModels = InstructorNaryadFile::where('user_id', $user->id)
            ->when($naryadIds, fn ($q) => $q->whereIn('id', $naryadIds))
            ->get();
        $queryModels = InstructorQueryList::where('user_id', $user->id)
            ->when($queryIds, fn ($q) => $q->whereIn('id', $queryIds))
            ->get();
        $shiftModels = InstructorShiftTable::where('user_id', $user->id)
            ->when($shiftIds, fn ($q) => $q->whereIn('id', $shiftIds))
            ->get();

        if ($naryadModels->isEmpty() || $queryModels->isEmpty()) {
            return back()->withErrors(['run' => 'Нужны хотя бы один наряд и один список ФИО.']);
        }

        $naryads = [];
        foreach ($naryadModels as $file) {
            $naryads[] = [
                'label' => $file->original_name,
                'meta' => $file->meta ?? [],
                'assignments' => $file->assignments ?? [],
            ];
        }
        $queryLines = [];
        foreach ($queryModels as $list) {
            foreach ($list->queries ?? [] as $line) {
                $queryLines[] = $line;
            }
        }
        $work = [];
        $weekend = [];
        foreach ($shiftModels as $table) {
            $entries = $table->entries ?? [];
            if ($table->kind === 'weekend') {
                $weekend = array_merge($weekend, $entries);
            } else {
                $work = array_merge($work, $entries);
            }
        }

        $result = SearchEngine::run($naryads, $queryLines, $work, $weekend);

        try {
            ClickHouseService::log('instructor_naryad_search', $user->id, [
                'naryads' => $naryadModels->count(),
                'queries' => count($queryLines),
                'hits' => count($result['hits']),
            ]);
        } catch (\Throwable) {
        }

        return back()->with('success', 'Найдено совпадений: '.count($result['hits']))
            ->with('instructor_naryad_result', $result['text']);
    }

    public function downloadResult()
    {
        $text = session('instructor_naryad_result');
        if (! is_string($text) || $text === '') {
            return back()->withErrors(['run' => 'Сначала выполните поиск.']);
        }

        return response($text, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="naryad_search_result.txt"',
        ]);
    }

    private function authorizeFile(int $userId): void
    {
        abort_unless($userId === Auth::id(), 403);
    }
}
