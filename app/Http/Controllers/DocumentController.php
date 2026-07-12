<?php
namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\QuizResult;
use App\Models\ActionLog;
use App\Services\AdminNotificationService;
use App\Services\ClickHouseService;
use App\Services\TelegramService;
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
            'order' => 'Приказы и распоряжения по депо',
            'orderForMetro' => 'Приказы по Метрополитену',
            'instruction' => 'Инструкции по Депо',
            'instForMetro' => 'Инструкции по Метрополитену',
            'technical' => 'Конспекты УПЦ',
            'student' => 'Конспект ТУ',
            'other' => 'Прочие материалы',
            'remember' => 'Памятки'
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
                'url' => \Illuminate\Support\Facades\URL::temporarySignedRoute('documents.file', now()->addMinutes(30), $document->id),
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

        // Get current device OS for viewer choice (ios -> old iframe, android/other -> PDF.js)
        $deviceOs = 'other';
        $deviceKey = request()->cookie('device_key');
        if ($deviceKey) {
            $device = $user->devices()->where('device_key', $deviceKey)->where('is_approved', true)->first();
            if ($device && $device->os) {
                $deviceOs = $device->os;
            }
        }

        return view('teaching.documents', [
            'groupedDocuments' => $documentsGrouped,
            'unreadCount' => $unreadResultsCount,
            'deviceOs' => $deviceOs
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

        // 1. Фиксируем событие просмотра (ActionLog + ClickHouse)
        ActionLog::log('view_document', [
            'document_id' => $document->id,
            'title' => $document->title,
        ]);

        // 2. Возвращаем защищённую ссылку на просмотр через приложение (inline, с проверкой)
        // Фронтенд должен использовать эту ссылку для просмотра (не для скачивания)
        $url = \Illuminate\Support\Facades\URL::temporarySignedRoute('documents.file', now()->addMinutes(30), $document->id);

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
                    $preSignedUrls[$ref->document_id] = \Illuminate\Support\Facades\URL::temporarySignedRoute('documents.file', now()->addMinutes(60), $ref->document_id);
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

    /**
     * Serve the document file through the application (protected, inline only).
     * This prevents direct S3 links and makes downloading harder.
     */
    public function serveFile(Document $document)
    {
        $user = auth()->user();
        $userRole = strtolower((string)($user->role->value ?? $user->role));

        // Permission check
        if (!in_array($userRole, ['super_admin', 'admin']) && $document->allowed_roles !== null) {
            if (!in_array($userRole, $document->allowed_roles)) {
                abort(403, 'Доступ к данному документу ограничен протоколом безопасности.');
            }
        }

        $disk = Storage::disk('s3');
        $path = $document->file_path;

        if (empty($path) || !$disk->exists($path)) {
            abort(404, 'Файл не найден');
        }

        // Log access to both DB (ActionLog) and ClickHouse
        ActionLog::log('view_document', [
            'document_id' => $document->id,
            'title' => $document->title,
        ]);

        $filename = pathinfo($path, PATHINFO_FILENAME) . '.pdf';
        $mime = 'application/pdf';

        // Force inline display to prevent auto-download on Android and other mobiles.
        // Use object tag on frontend + these headers.
        return $disk->response($path, $filename, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline; filename="' . addslashes($filename) . '"',
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Download the document (attachment).
     * For regular users this is a violation: log + alert + record.
     */
    public function downloadFile(Document $document)
    {
        $user = auth()->user();
        $userRole = strtolower((string)($user->role->value ?? $user->role));

        $isAdmin = in_array($userRole, ['super_admin', 'admin']);

        $disk = Storage::disk('s3');
        $path = $document->file_path;

        if (empty($path) || !$disk->exists($path)) {
            abort(404, 'Файл не найден');
        }

        $filename = basename($path);
        $mime = $disk->mimeType($path) ?: 'application/octet-stream';

        if (!$isAdmin) {
            // Violation: log to DB + CH
            ActionLog::log('violation_document_download', [
                'document_id' => $document->id,
                'title' => $document->title,
                'user_role' => $userRole,
            ]);

            // Send alerts to all channels
            try {
                AdminNotificationService::notify(
                    'violation',
                    'Нарушение регламента: попытка скачивания документа',
                    "Пользователь {$user->name} (роль: {$userRole}) попытался скачать документ «{$document->title}» (ID: {$document->id}). Доступно только чтение.",
                    [
                        'user_id' => $user->id,
                        'document_id' => $document->id,
                        'action' => 'download_attempt',
                    ]
                );

                // Telegram alert
                $tgMessage = "🚨 *Нарушение регламента использования документов*\n";
                $tgMessage .= "👤 Пользователь: {$user->name} (ID: {$user->id}, роль: {$userRole})\n";
                $tgMessage .= "📄 Документ: {$document->title} (ID: {$document->id})\n";
                $tgMessage .= "📍 Действие: попытка скачивания (запрещено, только просмотр)\n";
                $tgMessage .= "⏰ " . now()->format('Y-m-d H:i:s');

                TelegramService::send($tgMessage, null, 'Markdown');
            } catch (\Throwable $e) {
                \Log::error('Failed to send download violation alerts', ['error' => $e->getMessage()]);
            }

            abort(403, 'Скачивание документов запрещено согласно регламенту. Доступно только для чтения.');
        }

        // Log the download (admins)
        ActionLog::log('document_download', [
            'document_id' => $document->id,
            'title' => $document->title,
            'user_role' => $userRole,
        ]);

        ClickHouseService::log('document_download', $document->id, $document->title);

        return $disk->response($path, $filename, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'attachment; filename="' . addslashes($filename) . '"',
        ]);
    }

    /**
     * Serve document file for admin FileUpload UI (by path).
     * Used to provide fetchable URL for the form previews without direct S3 links.
     */
    public function adminServeDocument()
    {
        $path = request('path');
        if (empty($path)) {
            abort(404);
        }

        // Admin only
        $user = auth()->user();
        if (!$user || !in_array(strtolower((string)($user->role->value ?? $user->role)), ['super_admin', 'admin'])) {
            abort(403);
        }

        $disk = Storage::disk('s3');
        if (!$disk->exists($path)) {
            abort(404, 'Файл не найден');
        }

        $filename = basename($path);
        $mime = $disk->mimeType($path) ?: 'application/octet-stream';

        return $disk->response($path, $filename, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline; filename="' . addslashes($filename) . '"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
