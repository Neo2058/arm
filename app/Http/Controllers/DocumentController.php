<?php
namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\QuizResult;
use App\Services\ClickHouseService;
use Illuminate\Support\Facades\Storage;
use App\Models\Quiz;

class DocumentController extends Controller
{
    public function index()
    {
        $user = auth()->user();
        $userRole = strtolower((string)($user->role->value ?? $user->role));

        // Массив для перевода системных названий в читаемые
        $categoryLabels = [
            'manual' => 'Руководства по эксплуатации',
            'order' => 'Приказы и распоряжения',
            'technical' => 'Техническая документация',
            'other' => 'Прочие материалы'
        ];

        // Получаем документы
        $query = Document::with('quiz.questions');

        // КРИТИЧЕСКИ ВАЖНО: Если это не админ, фильтруем документы по его роли
        if (!in_array($userRole, ['super_admin', 'admin'])) {
            $query->where(function ($q) use ($userRole) {
                // Показываем документы, где роль пользователя есть в массиве allowed_roles,
                // ИЛИ документы, у которых allowed_roles равен null (доступны всем)
                $q->whereJsonContains('allowed_roles', $userRole)
                    ->orWhereNull('allowed_roles');
            });
        }

        // 1. Получаем документы со связями
        $documentsGrouped = $query->get()->map(function ($document) use ($categoryLabels) {
            // Определяем понятное имя категории, если её нет — пишем "Прочие материалы"
            $rawCategory = $document->category ?: 'other';
            $categoryName = $categoryLabels[$rawCategory] ?? $rawCategory;
            return [
                'id' => $document->id,
                'title' => $document->title,
                'category_key' => $rawCategory,
                'category_name' => $categoryName,
                'updatedAt' => $document->updated_at?->format('d.m.Y'),
                'url' => Storage::disk('s3')->temporaryUrl(
                    $document->file_path,
                    now()->addMinutes(20),
                    ['ResponseContentDisposition' => 'inline']
                ),
                'quiz' => $document->quiz ? [
                    'id' => $document->quiz->id,
                    'title' => $document->quiz->title,
                    'questionsCount' => $document->quiz->questions->count(),
                    'timeLimit' => $document->quiz->time_limit,
                ] : null,
            ];
        })
            // 2. Группируем по имени категории
            ->groupBy('category_name')
            // 3. Форматируем в удобную структуру для React-компонента
            ->map(function ($items, $categoryName) {
                return [
                    'name' => $categoryName,
                    'items' => $items->values()->toArray()
                ];
            })
            ->values()
            ->toArray();

        $unreadResultsCount = QuizResult::where('user_id', auth()->id())
            ->where('is_viewed', false)
            ->count();

        return view('teaching.documents', [
            'groupedDocuments' => $documentsGrouped,
            'unreadCount' => $unreadResultsCount // <-- Передали во view
        ]);
    }

    public function show(Document $document)
    {
        $user = auth()->user();
        $userRole = strtolower((string)($user->role->value ?? $user->role));

        // Защита прямой ссылки: если документ ограничен и роль пользователя не совпадает
        if (!in_array($userRole, ['super_admin', 'admin']) && $document->allowed_roles !== null) {
            if (!in_array($userRole, $document->allowed_roles)) {
                abort(403, 'Доступ к данному документу ограничен протоколом безопасности.');
            }
        }

        // 1. Фиксируем событие просмотра в ClickHouse (передаем ID и Название документа)
        ClickHouseService::log('view_document', $document->id, $document->title);

        // 2. Генерируем свежую временную ссылку конкретно для просмотра
        $url = Storage::disk('s3')->temporaryUrl(
            $document->file_path,
            now()->addMinutes(30), // Ссылка для чтения на 30 минут
            ['ResponseContentDisposition' => 'inline']
        );

        // 3. Возвращаем JSON (если React запрашивает ссылку по клику)
        // или отдельный Blade-вид
        return response()->json([
            'url' => $url,
            'title' => $document->title
        ]);
    }

    public function showGeneralQuiz(Quiz $quiz)
    {
        // Жадная загрузка вопросов, ответов, ссылок и самих документов
        $quiz = Quiz::whereNull('document_id')->where('is_active', true)->first();

        if (!$quiz) {
            return redirect()->route('mainMenu')->with('error', 'Активных аттестаций не найдено');
        }

        // Собираем все уникальные документы, упомянутые в тесте, и делаем для них S3-ссылки
        $preSignedUrls = [];
        foreach ($quiz->questions as $question) {
            foreach ($question->references as $ref) {
                if ($ref->document && !isset($preSignedUrls[$ref->document_id])) {
                    $preSignedUrls[$ref->document_id] = Storage::disk('s3')->temporaryUrl(
                        $ref->document->file_path,
                        now()->addMinutes(60), // Аттестация длинная, даем 60 минут
                        ['ResponseContentDisposition' => 'inline']
                    );
                }
            }
        }

        // Формируем структуру данных для React
        $quizData = [
            'id' => $quiz->id,
            'title' => $quiz->title,
            'time_limit' => $quiz->time_limit,
            'questions' => $quiz->questions->map(function ($q) use ($preSignedUrls) {
                return [
                    'id' => $q->id,
                    'question_text' => $q->question_text,
                    'answers' => $q->answers,
                    'references' => $q->references->map(function ($r) use ($preSignedUrls) {
                        return [
                            'anchor_text' => $r->anchor_text,
                            'page' => $r->page_number,
                            'url' => $preSignedUrls[$r->document_id] ?? null
                        ];
                    })
                ];
            })
        ];

        $unreadResultsCount = QuizResult::where('user_id', auth()->id())
            ->where('is_viewed', false)
            ->count();


        return view('quiz.general', [
            'quiz' => $quizData,
            'unreadCount' => $unreadResultsCount // <-- Передали во view
            ]);
    }

    public function history()
    {
        $user = auth()->user();

        // Находим все тесты пользователя
        $results = QuizResult::where('user_id', $user->id)
            ->with('quiz')
            ->latest()
            ->get();

        // Мгновенно помечаем их как просмотренные, чтобы обнулить счетчик в сайдбаре
        QuizResult::where('user_id', $user->id)->where('is_viewed', false)->update(['is_viewed' => true]);

        // Передаем результаты в форму (подсчет unreadCount передаем как 0, так как они только что прочитаны)
        return view('quiz.results-history', [
            'results' => $results,
            'unreadCount' => 0
        ]);
    }
}
