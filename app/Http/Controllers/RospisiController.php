<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\FormularTask;
use App\Models\InstructionCategory;
use App\Models\UserFormularLog;
use App\Models\UserInstructionSignature;
use App\Services\ClickHouseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RospisiController extends Controller
{
    public function index()
    {
        $user = Auth::user();

        // Получаем все активные категории инструктажей
        $categories = InstructionCategory::where('is_active', true)
            ->orderBy('sort_order')
            ->with(['documents' => function ($q) {
                $q->whereNotNull('instruction_category_id'); // только те, что для инструктажей
            }])
            ->get();

        // Для каждой категории собираем документы с статусом подписи пользователя
        $categoriesData = $categories->map(function ($category) use ($user) {
            $documents = $category->documents->map(function ($doc) use ($user) {
                $signature = UserInstructionSignature::where('user_id', $user->id)
                    ->where('document_id', $doc->id)
                    ->first();

                $url = (function () use ($doc) {
                    try {
                        return \Illuminate\Support\Facades\Storage::disk('s3')->temporaryUrl(
                            $doc->file_path,
                            now()->addMinutes(30),
                            ['ResponseContentDisposition' => 'inline']
                        );
                    } catch (\Throwable $e) {
                        \Log::warning('S3 temp url failed for rospisi', ['doc' => $doc->id, 'err' => $e->getMessage()]);
                        return null;
                    }
                })();

                return [
                    'id' => $doc->id,
                    'title' => $doc->title,
                    'has_quiz' => (bool) $doc->quiz,
                    'quiz_id' => $doc->quiz?->id,
                    'signed' => (bool) $signature,
                    'signed_at' => $signature?->signed_at,
                    'url' => $url,
                ];
            });

            return [
                'id' => $category->id,
                'name' => $category->name,
                'documents' => $documents,
            ];
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
                ];
            });

        return view('teaching.rosisi', [
            'categoriesData' => $categoriesData,
            'formularTasks' => $formularTasks,
        ]);
    }

    public function signDocument(Request $request, Document $document)
    {
        $user = Auth::user();

        // Проверка, что документ принадлежит категории инструктажа
        if (!$document->instruction_category_id) {
            abort(403, 'Этот документ не предназначен для росписи.');
        }

        UserInstructionSignature::updateOrCreate(
            [
                'user_id' => $user->id,
                'document_id' => $document->id,
            ],
            [
                'instruction_category_id' => $document->instruction_category_id,
                'signed_at' => now(),
                'notes' => $request->notes,
            ]
        );

        // Логируем в ClickHouse
        ClickHouseService::log('instruction_signed', $document->id, [
            'user_id' => $user->id,
            'document_title' => $document->title,
        ]);

        return back()->with('success', 'Роспись поставлена.');
    }

    public function logFormular(Request $request, FormularTask $task)
    {
        $user = Auth::user();

        UserFormularLog::updateOrCreate(
            [
                'user_id' => $user->id,
                'formular_task_id' => $task->id,
            ],
            [
                'completed_at' => now(),
                'entry_text' => $request->entry_text,
                'notes' => $request->notes,
            ]
        );

        ClickHouseService::log('formular_logged', $task->id, [
            'user_id' => $user->id,
            'task_title' => $task->title,
        ]);

        return back()->with('success', 'Запись в формуляр отмечена.');
    }

    public function statistics()
    {
        $user = Auth::user();
        $userRole = strtolower((string)($user->role->value ?? $user->role));

        if (!in_array($userRole, ['super_admin', 'admin', 'instructor'])) {
            abort(403, 'Доступ только для инструкторов и выше.');
        }

        $signatures = UserInstructionSignature::with(['user', 'document', 'category'])
            ->latest('signed_at')
            ->paginate(15, ['*'], 'signatures_page');

        $logs = UserFormularLog::with(['user', 'task'])
            ->latest('completed_at')
            ->paginate(15, ['*'], 'logs_page');

        return view('teaching.rosisi.statistics', compact('signatures', 'logs'));
    }
}
