<?php

use App\Http\Controllers\Api\QuizResultController;
use App\Http\Controllers\Api\WorkShiftController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BackstageController;
use App\Http\Controllers\BarrierController;
use App\Http\Controllers\BugReportController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\TelegramKeyBotController;
use App\Http\Controllers\TrainingController;
use App\Models\QuizResult;
use Illuminate\Support\Facades\Route;
use App\Models\Quiz;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\TCHMJournalController;
use App\Http\Controllers\AccessRequestController;
use App\Http\Middleware\CheckUserExistence;
use App\Http\Middleware\CheckDeviceBinding;
use App\Http\Middleware\CheckDynamicBarrier;

/*
|--------------------------------------------------------------------------
| Public Routes
|--------------------------------------------------------------------------
*/

Route::get('/', function () {
    return view('main.welcome');
});

// Публичная страница описания сервиса и форма заявки на учётную запись
Route::get('/about', [AccessRequestController::class, 'show'])->name('about');
Route::post('/about/request', [AccessRequestController::class, 'submit'])
    ->name('about.request')
    ->middleware('throttle:5,60'); // Строгая защита: не более 5 заявок в час с одного IP

Route::get('/login', [AuthController::class, 'showLogin'])
    ->name('login');

Route::post('/login', [AuthController::class, 'login'])
    ->name('login.post');

Route::post('/logout', [AuthController::class, 'logout'])
    ->name('logout');

/*
|--------------------------------------------------------------------------
| Protected Routes
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', CheckUserExistence::class, CheckDeviceBinding::class, CheckDynamicBarrier::class])->group(function () {
    /*
    |--------------------------------------------------------------------------
    | Main Menu
    |--------------------------------------------------------------------------
    */
    Route::get('/mainMenu', function () {
        return view('main.mainMenu');
    })->name('mainMenu');

    // Временный просмотрщик нарядов (PDF от админов)
    Route::get('/naryady', [\App\Http\Controllers\NaryadViewerController::class, 'index'])->name('naryady.index');
    Route::get('/naryady/{naryad}', [\App\Http\Controllers\NaryadViewerController::class, 'show'])->name('naryady.show');
    Route::get('/naryady/{naryad}/search', [\App\Http\Controllers\NaryadViewerController::class, 'search'])->name('naryady.search');

    /*
   |--------------------------------------------------------------------------
   | Barrier
   |--------------------------------------------------------------------------
   */

    // Сюда же перенеси маршруты барьера из прошлого шага, если они ещё не добавлены
    Route::get('/barrier', [BarrierController::class, 'show'])->name('barrier.show');
    Route::post('/api/barrier/verify', [BarrierController::class, 'verify'])->name('barrier.verify');

    // Страница с формой подачи заявки
    Route::get('/device-register', [DeviceController::class, 'showRegisterForm'])->name('device.register.form');
    // Подача заявки (обработчик POST)
    Route::post('/api/device/register', [DeviceController::class, 'registerDevice'])->name('device.register.submit');

    Route::post('/telegram/webhook', [TelegramKeyBotController::class, 'webhook']);

    Route::get('/api/work-shifts', [WorkShiftController::class, 'index']);
    Route::post('/api/work-shifts', [WorkShiftController::class, 'store']);
    Route::post('/api/work-shifts/preview', [WorkShiftController::class, 'preview']);

    Route::get('/worktime', function () {
        // Подсчитываем счетчик непрочитанных, чтобы сайдбар не ломался
        $unreadResultsCount = QuizResult::where('user_id', auth()->id())
            ->where('is_viewed', false)
            ->count();

        return view('worktime.worktime', [
            'unreadCount' => $unreadResultsCount
        ]);
    })->name('work.time.index'); // Даем имя роуту
    /*
    |--------------------------------------------------------------------------
    | Teaching Pages (Shared Layout)
    |--------------------------------------------------------------------------
    */

    // Главная страница обучения
    Route::get('/teaching', function () {
        return view('teaching.layouts.index');
    })->name('teaching.timer');

    // Таймер (отдельный route при необходимости)
    Route::get('/timer', function () {
        return view('teaching.timer');
    })->name('timer');

    // Новый раздел Росписи (дублирует функционал Telegram + ведение формуляра)
    Route::get('/rosisi', [\App\Http\Controllers\RospisiController::class, 'index'])->name('rosisi.index');
    Route::post('/rosisi/sign/{document}', [\App\Http\Controllers\RospisiController::class, 'signDocument'])->name('rosisi.sign');
    Route::post('/rosisi/log-formular/{task}', [\App\Http\Controllers\RospisiController::class, 'logFormular'])->name('rosisi.log-formular');

    // Статистика росписей (для инструкторов, админов)
    Route::get('/rosisi/statistics', [\App\Http\Controllers\RospisiController::class, 'statistics'])->name('rosisi.statistics');

    Route::get('/results-history', [DocumentController::class, 'history'])->name('quiz.results.history');


    /*
    |--------------------------------------------------------------------------
    | Documents
    |--------------------------------------------------------------------------
    */

    // Новый route для страницы документов
    Route::get('/documents', [DocumentController::class, 'index'])
        ->name('documents.index');

    // Новый route для фиксации клика и получения свежей ссылки
    Route::get('/api/documents/{document}/click', [DocumentController::class, 'show']);

    // Protected file serving for viewing only (inline, no easy download)
    Route::get('/documents/{document}/file', [DocumentController::class, 'serveFile'])
        ->name('documents.file');

    // Download route - for admins OK, for regular users - violation log + alert
    Route::get('/documents/{document}/download', [DocumentController::class, 'downloadFile'])
        ->name('documents.download');
    /*
    |--------------------------------------------------------------------------
    | Quiz
    |--------------------------------------------------------------------------
    */

    Route::get('/quiz/{quiz}', function (Quiz $quiz) {

        $quizData = $quiz->load('questions.answers');

        return view('quiz.show', [
            'quiz' => $quizData
        ]);

    })->name('quiz.show');

    Route::get('/general-quiz', [DocumentController::class, 'showGeneralQuiz'])->name('quiz.general');


    /*
    |--------------------------------------------------------------------------
    | API
    |--------------------------------------------------------------------------
    */

    Route::post('/api/quiz-results', [QuizResultController::class, 'store'])
        ->name('quiz-results.store');
    /*
   |--------------------------------------------------------------------------
   | Bag reports
   |--------------------------------------------------------------------------
   */
    Route::post('/api/bug-report', [BugReportController::class, 'store'])->name('bug.report.store');

    // User action logging (for phones, naryads, explanations, and other activities)
    Route::post('/api/actions/log', [\App\Http\Controllers\ActionLogController::class, 'store'])
        ->name('actions.log');

    // Backstage — Связь с разработчиком + поддержка проекта
    Route::get('/backstage', [BackstageController::class, 'index'])->name('backstage.index');
    Route::post('/backstage', [BackstageController::class, 'store'])->name('backstage.store');
    Route::post('/backstage/support', [BackstageController::class, 'support'])->name('backstage.support');

    // Webhook для ЮKassa (публичный, без auth)
    Route::post('/webhooks/yookassa', [BackstageController::class, 'yookassaWebhook'])->name('webhooks.yookassa');

    /*
    |--------------------------------------------------------------------------
    | Training / Техническая учёба (дублирует функционал Telegram бота)
    | Mobile-first, с возможностью комментариев и реакций
    |--------------------------------------------------------------------------
    */
    Route::prefix('training')->group(function () {
        Route::get('/', [TrainingController::class, 'index'])->name('training.topics');
        Route::get('/{topic:slug}', [TrainingController::class, 'showTopic'])->name('training.topic');

        // Шаблонные страницы для медиа (video и audio)
        Route::get('/video/{material}', [TrainingController::class, 'showVideo'])->name('training.video');
        Route::get('/audio/{material}', [TrainingController::class, 'showAudio'])->name('training.audio');

        // Комментарии и реакции (архитектура заложена)
        Route::post('/{material}/comment', [TrainingController::class, 'storeComment'])->name('training.comment.store');
        Route::post('/{material}/react', [TrainingController::class, 'storeReaction'])->name('training.reaction.store');
    });

    // Подстройки смен (новый пункт в карусели "Подстройки")
    Route::get('/podstroiki', [\App\Http\Controllers\PodstroikiController::class, 'index'])->name('podstroiki.index');
    Route::post('/podstroiki', [\App\Http\Controllers\PodstroikiController::class, 'store'])->name('podstroiki.store');
    Route::post('/podstroiki/{podstroika}/status', [\App\Http\Controllers\PodstroikiController::class, 'updateStatus'])->name('podstroiki.update-status');

    // Рабочий журнал ТЧМ (только для инструкторов)
    Route::get('/journal', [TCHMJournalController::class, 'index'])->name('journal.index');
    Route::post('/journal/todo', [TCHMJournalController::class, 'addTodo'])->name('journal.todo.add');
    Route::post('/journal/todo/{id}/complete', [TCHMJournalController::class, 'completeTodo'])->name('journal.todo.complete');
    Route::post('/journal/todo/{id}/update', [TCHMJournalController::class, 'updateTodo'])->name('journal.todo.update');
    Route::delete('/journal/todo/{id}', [TCHMJournalController::class, 'deleteTodo'])->name('journal.todo.destroy');
    Route::post('/journal/document', [TCHMJournalController::class, 'uploadDocument'])->name('journal.document.upload');
    Route::post('/journal/ask', [TCHMJournalController::class, 'askDocument'])->name('journal.ask');

    // Журнал ТЧМ - новые разделы
    Route::get('/journal/settings', [TCHMJournalController::class, 'settings'])->name('journal.settings');
    Route::post('/journal/settings', [TCHMJournalController::class, 'updateSettings'])->name('journal.settings.update');
    Route::get('/journal/standards', [TCHMJournalController::class, 'standards'])->name('journal.standards');
    Route::post('/journal/standards', [TCHMJournalController::class, 'updateStandards'])->name('journal.standards.update');
    Route::get('/journal/history', [TCHMJournalController::class, 'history'])->name('journal.history');
    Route::get('/journal/report', [TCHMJournalController::class, 'report'])->name('journal.report');
    Route::post('/journal/report/vacation', [TCHMJournalController::class, 'addVacation'])->name('journal.report.vacation.add');
    Route::delete('/journal/report/vacation/{id}', [TCHMJournalController::class, 'deleteVacation'])->name('journal.report.vacation.delete');

    /*
    |--------------------------------------------------------------------------
    | Планирование наряда для Нарядчика (уникальный сайдбар + AJAX справочники + сетка)
    | Только для ролей naryadchik / dispatcher. Отдельный layout без основного sidebar.
    | Данные о маршрутах/временах — из WorkShift + RoutesCatalog.
    |--------------------------------------------------------------------------
    */
    Route::prefix('naryad')->group(function () {
        Route::get('/', [\App\Http\Controllers\NaryadPlanningController::class, 'index'])->name('naryad.index');

        // AJAX-загрузка разделов (partials) — возвращают HTML без layout
        Route::get('/partial/setka', [\App\Http\Controllers\NaryadPlanningController::class, 'partialSetka'])->name('naryad.partial.setka');
        Route::get('/partial/crews', [\App\Http\Controllers\NaryadPlanningController::class, 'partialCrews'])->name('naryad.partial.crews');
        Route::get('/partial/variants', [\App\Http\Controllers\NaryadPlanningController::class, 'partialVariants'])->name('naryad.partial.variants');
        Route::get('/partial/calendar', [\App\Http\Controllers\NaryadPlanningController::class, 'partialCalendar'])->name('naryad.partial.calendar');
        Route::get('/partial/types', [\App\Http\Controllers\NaryadPlanningController::class, 'partialTypes'])->name('naryad.partial.types');
        Route::get('/partial/users', [\App\Http\Controllers\NaryadPlanningController::class, 'partialUsers'])->name('naryad.partial.users');

        // Действия сохранения (AJAX из сетки и справочников)
        Route::post('/assign', [\App\Http\Controllers\NaryadPlanningController::class, 'assign'])->name('naryad.assign');
        Route::post('/unassign', [\App\Http\Controllers\NaryadPlanningController::class, 'unassign'])->name('naryad.unassign');

        // Обновление планировочных флагов пользователей (расширенный справочник)
        Route::post('/user-profile/{profile}/flags', [\App\Http\Controllers\NaryadPlanningController::class, 'updateUserFlags'])
            ->name('naryad.user-profile.update-flags');

        // Справочник составов (т6/т5)
        Route::post('/crews', [\App\Http\Controllers\NaryadPlanningController::class, 'storeCrew'])->name('naryad.crews.store');
        Route::get('/crews', fn () => redirect()->route('naryad.partial.crews'));

        // Типы графиков
        Route::post('/types', [\App\Http\Controllers\NaryadPlanningController::class, 'storeType'])->name('naryad.types.store');
        Route::put('/types/{type}', [\App\Http\Controllers\NaryadPlanningController::class, 'updateType'])->name('naryad.types.update');
        Route::delete('/types/{type}', [\App\Http\Controllers\NaryadPlanningController::class, 'destroyType'])->name('naryad.types.destroy');
        Route::get('/types', fn () => redirect()->route('naryad.partial.types'));

        // Варианты маршрутов
        Route::post('/variants', [\App\Http\Controllers\NaryadPlanningController::class, 'storeVariant'])->name('naryad.variants.store');
        Route::put('/variants/{variant}', [\App\Http\Controllers\NaryadPlanningController::class, 'updateVariant'])->name('naryad.variants.update');
        Route::delete('/variants/{variant}', [\App\Http\Controllers\NaryadPlanningController::class, 'destroyVariant'])->name('naryad.variants.destroy');
        // Защита от GET на POST-only эндпоинт (например, при прямом переходе или истории браузера)
        Route::get('/variants', fn () => redirect()->route('naryad.partial.variants'));

        // Отвлечения (DeviationsCatalog)
        Route::get('/partial/deviations', [\App\Http\Controllers\NaryadPlanningController::class, 'partialDeviations'])->name('naryad.partial.deviations');
        Route::post('/deviations', [\App\Http\Controllers\NaryadPlanningController::class, 'storeDeviation'])->name('naryad.deviations.store');
        Route::put('/deviations/{deviation}', [\App\Http\Controllers\NaryadPlanningController::class, 'updateDeviation'])->name('naryad.deviations.update');
        Route::delete('/deviations/{deviation}', [\App\Http\Controllers\NaryadPlanningController::class, 'destroyDeviation'])->name('naryad.deviations.destroy');
        Route::get('/deviations', fn () => redirect()->route('naryad.partial.deviations'));

        // Календарь квот (batch save for month)
        Route::post('/calendar', [\App\Http\Controllers\NaryadPlanningController::class, 'saveCalendar'])->name('naryad.calendar.save');

        // Начальные условия (нормы и дополнительные ограничения)
        Route::get('/partial/norms', [\App\Http\Controllers\NaryadPlanningController::class, 'partialNorms'])->name('naryad.partial.norms');
        Route::post('/norms', [\App\Http\Controllers\NaryadPlanningController::class, 'updateNorm'])->name('naryad.norms.update');
        Route::post('/extra-conditions', [\App\Http\Controllers\NaryadPlanningController::class, 'storeExtraCondition'])->name('naryad.extra_conditions.store');
        Route::put('/extra-conditions/{extra}', [\App\Http\Controllers\NaryadPlanningController::class, 'updateExtraCondition'])->name('naryad.extra_conditions.update');
        Route::delete('/extra-conditions/{extra}', [\App\Http\Controllers\NaryadPlanningController::class, 'destroyExtraCondition'])->name('naryad.extra_conditions.destroy');

        // Лимиты на подстройки (per user per month, задаёт нарядчик)
        Route::post('/podstroika-limit', [\App\Http\Controllers\NaryadPlanningController::class, 'savePodstroikaLimit'])->name('naryad.podstroika-limit.save');
    });
});


