<?php
namespace App\Http\Controllers;

use App\Models\Document;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\Request;

class DocumentController extends Controller
{
    public function index()
    {
        // 1. Используем with('questions') через связь в модели Quiz,
        // чтобы сразу получить количество вопросов.
        $documents = Document::with('quiz.questions')->get()->map(function ($document) {
            return [
                'id' => $document->id,
                'title' => $document->title,
                'updatedAt' => $document->updated_at?->format('d.m.Y'),
                'url' => Storage::disk('s3')->temporaryUrl(
                    $document->file_path,
                    now()->addMinutes(20),
                    ['ResponseContentDisposition' => 'inline']
                ),
                // Добавляем данные о привязанном тесте
                'quiz' => $document->quiz ? [
                    'id' => $document->quiz->id,
                    'title' => $document->quiz->title,
                    'questionsCount' => $document->quiz->questions->count(),
                    'timeLimit' => $document->quiz->time_limit,
                ] : null,
            ];
        });

        return view('documents.index', [
            'documents' => $documents
        ]);
    }
}
