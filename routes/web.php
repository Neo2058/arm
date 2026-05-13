<?php

use App\Http\Controllers\Api\QuizResultController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DocumentController;
use Illuminate\Support\Facades\Route;
use App\Models\Quiz;

Route::get('/', function () {
    return view('main.welcome');
});

Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->name('login.post');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

// Закрытая зона (пример)
Route::middleware(['auth'])->group(function () {
    Route::get('/mainMenu', function () {
        return view('main/mainMenu');
    })->name('mainMenu');

    // Страница обучения (Blade)
    Route::middleware(['auth'])->group(function () {
        // Теперь /index будет вызывать контроллер
        Route::get('/index', [DocumentController::class, 'index'])->name('index');

        // Если /documents тебе тоже нужен, можешь оставить или удалить
        Route::get('/documents', [DocumentController::class, 'index'])->name('documents.index');
    });

    Route::get('/quiz/{quiz}', function (Quiz $quiz) {
        // Загружаем тест вместе с вопросами и вариантами ответов
        $quizData = $quiz->load('questions.answers');

        return view('quiz.show', [
            'quiz' => $quizData
        ]);
    })->name('quiz.show');

    Route::post('/api/quiz-results', [QuizResultController::class, 'store']);
});
