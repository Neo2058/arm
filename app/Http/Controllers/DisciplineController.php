<?php

namespace App\Http\Controllers;

use App\Models\DisciplineFile;
use App\Services\Discipline\DisciplineAccess;
use App\Services\Discipline\DisciplineService;
use App\Services\FileProxyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\HeaderUtils;

class DisciplineController extends Controller
{
    public function index(Request $request, DisciplineAccess $access)
    {
        abort_unless($access->current($request->user())->isInstructor(), 403);
        $assignments = $access->assignments($request->user())->with('card.author')->latest('updated_at')->paginate(30);

        return view('discipline.index', compact('assignments'));
    }

    public function show(Request $request, int $assignment, DisciplineAccess $access)
    {
        $assignment = $access->view($request->user(), $assignment);
        DB::table('discipline_notifications')->where('user_id', $request->user()->id)->where('assignment_id', $assignment->id)->whereNull('read_at')->update(['read_at' => now()]);

        return view('discipline.show', ['assignment' => $assignment, 'hide_sidebar' => ! $request->user()->isInstructor()]);
    }

    public function submit(Request $request, int $assignment, DisciplineService $service)
    {
        $request->validate([
            'report_files' => 'sometimes|array|max:5', 'report_files.*' => 'file|max:10240',
            'explanation_files' => 'sometimes|array|max:5', 'explanation_files.*' => 'file|max:10240',
            'other_files' => 'sometimes|array|max:5', 'other_files.*' => 'file|max:10240',
        ]);
        $service->submit($request->user(), $assignment, $request->only(['operation_id', 'version', 'response']), [
            'report' => $request->file('report_files', []), 'explanation' => $request->file('explanation_files', []), 'other' => $request->file('other_files', []),
        ]);

        return redirect()->route('discipline.show', $assignment)->with('success', 'Отчёт отправлен на проверку.');
    }

    public function review(Request $request, int $assignment, DisciplineService $service)
    {
        $service->review($request->user(), $assignment, $request->only(['operation_id', 'version', 'report_id', 'decision', 'comment']));

        return redirect()->route('discipline.show', $assignment)->with('success', 'Решение сохранено.');
    }

    public function download(Request $request, int $file, DisciplineAccess $access, FileProxyService $proxy)
    {
        $file = DisciplineFile::with('report')->findOrFail($file);
        $access->view($request->user(), $file->report->assignment_id);

        return $proxy->respond($file->path, $file->name, [
            'Content-Type' => $file->mime, 'Content-Disposition' => HeaderUtils::makeDisposition('attachment', $file->name, 'attachment'),
            'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => 'sandbox', 'Cache-Control' => 'private, no-store',
        ]);
    }
}
