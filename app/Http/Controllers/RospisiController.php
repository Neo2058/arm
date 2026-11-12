<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\FormularTask;
use App\Models\UserFormularLog;
use App\Models\UserInstructionSignature;
use App\Services\FileProxyService;
use App\Services\FormularService;
use App\Services\InstructionSignatures;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RospisiController extends Controller
{
    public function __construct(private FileProxyService $files) {}

    public function index()
    {
        $user = Auth::user();

        $categoriesData = collect(app(InstructionSignatures::class)->categories($user))->map(function ($category) {
            $category['documents'] = collect($category['documents'])->map(function ($doc) {
                $doc['has_quiz'] = $doc['quiz_id'] !== null;
                $doc['url'] = $doc['has_file'] ? $this->files->temporarySignedRoute('documents.file', 30, $doc['id']) : null;

                return $doc;
            });

            return $category;
        });

        // Отдельный список для формуляра
        $formularTasks = FormularTask::where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(function ($task) use ($user) {
                $log = UserFormularLog::where('user_id', $user->id)
                    ->where('formular_task_id', $task->id)
                    ->first();

                return [
                    'id' => $task->id,
                    'title' => $task->title,
                    'description' => $task->description,
                    'completed' => (bool) $log,
                    'completed_at' => $log?->completed_at,
                    'entry_text' => $log?->entry_text,
                    'status' => $log?->status, 'version' => $log?->version ?? 0,
                ];
            });

        return view('teaching.rosisi', [
            'categoriesData' => $categoriesData,
            'formularTasks' => $formularTasks,
        ]);
    }

    public function signDocument(Request $request, Document $document)
    {
        app(InstructionSignatures::class)->sign($request->user(), $document->id, $request->all());

        return back()->with('success', 'Ознакомление с документом подтверждено.');
    }

    public function logFormular(Request $request, FormularTask $task)
    {
        app(FormularService::class)->mutate($request->user(), 'submit', $task->id, $request->all());

        return redirect()->route('formular.index')->with('success', 'Запись отправлена на проверку.');
    }

    public function statistics()
    {
        $user = Auth::user();

        if (! $user->canViewRospisiStatistics()) {
            abort(403, 'Доступ только для инструкторов и выше.');
        }

        $signatures = UserInstructionSignature::with(['user', 'document', 'category'])
            ->latest('signed_at')
            ->paginate(15, ['*'], 'signatures_page');

        $logs = UserFormularLog::with(['user', 'task'])
            ->when($user->isInstructor(), fn ($q) => $q->whereHas('user', fn ($u) => $u->where('is_active', true)->where('role', 'driver')->whereHas('profile', fn ($p) => $p->where('column', $user->profile?->column)))
                ->when(! $user->profile?->column, fn ($q) => $q->whereRaw('1 = 0')))
            ->latest('completed_at')
            ->paginate(15, ['*'], 'logs_page');

        return view('teaching.rosisi.statistics', compact('signatures', 'logs'));
    }
}
