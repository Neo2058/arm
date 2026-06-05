<?php

use App\Http\Controllers\Api\QuizResultController;
use App\Http\Controllers\Api\WorkShiftController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BarrierController;
use App\Http\Controllers\BugReportController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\TelegramKeyBotController;
use App\Models\QuizResult;
use Illuminate\Support\Facades\Route;
use App\Models\Quiz;
use App\Http\Controllers\DeviceController;

/*
|--------------------------------------------------------------------------
| Public Routes
|--------------------------------------------------------------------------
*/

Route::get('/', function () {
    return view('main.welcome');
});

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

Route::middleware(['auth'])->group(function () {
    /*
    |--------------------------------------------------------------------------
    | Main Menu
    |--------------------------------------------------------------------------
    */
    Route::get('/mainMenu', function () {
        return view('main.mainMenu');
    })->name('mainMenu');

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

    Route::get('/results-history', [DocumentController::class, 'history'])->name('quiz.results.history');


    /*
    |--------------------------------------------------------------------------
    | Documents
    |--------------------------------------------------------------------------
    */

    // Старый функционал сохранён
//    Route::get('/index', [DocumentController::class, 'index'])
//        ->name('index');

    // Новый route для страницы документов
    Route::get('/documents', [DocumentController::class, 'index'])
        ->name('documents.index');

    // Новый route для фиксации клика и получения свежей ссылки
    Route::get('/api/documents/{document}/click', [DocumentController::class, 'show']);
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

});


