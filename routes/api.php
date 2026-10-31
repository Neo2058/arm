<?php

use App\Http\Controllers\Api\Mobile\AuthController;
use App\Http\Controllers\Api\Mobile\DisciplineController;
use App\Http\Controllers\Api\Mobile\DocumentController;
use App\Http\Controllers\Api\Mobile\FileController;
use App\Http\Controllers\Api\Mobile\JournalController;
use App\Http\Controllers\Api\Mobile\NaryadController;
use App\Http\Controllers\Api\Mobile\PhoneController;
use App\Http\Controllers\Api\Mobile\ProfileController;
use App\Http\Controllers\Api\Mobile\QuizController;
use App\Http\Controllers\Api\Mobile\TelegramAccountController;
use App\Http\Controllers\Api\Mobile\TrainingController;
use App\Http\Middleware\EnsureActiveApiUser;
use Illuminate\Support\Facades\Route;

Route::prefix('mobile')->group(function () {
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

    Route::get('/files/documents/{document}', [FileController::class, 'document'])
        ->name('mobile.files.documents')
        ->middleware('signed');
    Route::get('/files/training/{material}', [FileController::class, 'training'])
        ->name('mobile.files.training')
        ->middleware('signed');
    Route::get('/files/naryads/{naryad}', [FileController::class, 'naryad'])
        ->name('mobile.files.naryads')
        ->middleware('signed');

    Route::middleware(['auth:sanctum', EnsureActiveApiUser::class])->group(function () {
        Route::get('/account/profile', [ProfileController::class, 'show']);
        Route::patch('/account/profile', [ProfileController::class, 'update'])->middleware('throttle:20,1');
        Route::get('/me', [AuthController::class, 'me']);
        Route::prefix('worktime')->group(function () { require __DIR__.'/personal-worktime.php'; });
        Route::post('/logout', [AuthController::class, 'logout']);

        Route::get('/account/telegram', [TelegramAccountController::class, 'show']);
        Route::post('/account/telegram', [TelegramAccountController::class, 'issue'])->middleware('throttle:5,10');
        Route::delete('/account/telegram', [TelegramAccountController::class, 'destroy'])->middleware('throttle:5,10');

        Route::get('/documents', [DocumentController::class, 'index']);
        Route::get('/documents/{document}', [DocumentController::class, 'show']);
        Route::post('/documents/{document}/violation', [DocumentController::class, 'reportViolation'])
            ->middleware('throttle:30,1');

        Route::get('/training/topics', [TrainingController::class, 'topics']);
        Route::get('/training/topics/{topic:slug}', [TrainingController::class, 'topic']);
        Route::get('/training/materials/{material}', [TrainingController::class, 'material']);
        Route::post('/training/materials/{material}/comments', [TrainingController::class, 'storeComment']);

        Route::get('/quizzes', [QuizController::class, 'index']);
        Route::get('/quizzes/{quiz}', [QuizController::class, 'show']);
        Route::post('/quizzes/{quiz}/results', [QuizController::class, 'submit']);

        Route::get('/naryads', [NaryadController::class, 'index']);
        Route::get('/naryads/{naryad}', [NaryadController::class, 'show']);

        Route::prefix('journal/training-requests')->controller(\App\Http\Controllers\Api\Mobile\TrainingRequestController::class)->group(function () {
            Route::get('/', 'index');
            Route::get('/crew', 'crew');
            Route::post('/', 'store')->middleware('throttle:20,1');
            Route::post('/periods', 'periods')->middleware('throttle:20,1');
            Route::patch('/{trainingRequest}', 'update')->whereNumber('trainingRequest')->middleware('throttle:20,1');
            Route::delete('/{trainingRequest}', 'destroy')->whereNumber('trainingRequest')->middleware('throttle:20,1');
            Route::post('/{trainingRequest}/complete', 'complete')->whereNumber('trainingRequest')->middleware('throttle:20,1');
        });
        Route::get('/journal', [JournalController::class, 'summary']);
        Route::get('/journal/tasks', [JournalController::class, 'tasks']);
        Route::post('/journal/tasks', [JournalController::class, 'createTask'])->middleware('throttle:20,1');
        Route::patch('/journal/tasks/{task}', [JournalController::class, 'updateTask'])->whereNumber('task')->middleware('throttle:20,1');
        Route::post('/journal/tasks/{task}/complete', [JournalController::class, 'completeTask'])->whereNumber('task')->middleware('throttle:20,1');
        Route::delete('/journal/tasks/{task}', [JournalController::class, 'deleteTask'])->whereNumber('task')->middleware('throttle:20,1');
        Route::get('/journal/crew', [JournalController::class, 'crew']);

        Route::get('/phones', [PhoneController::class, 'index']);
        Route::post('/phones', [PhoneController::class, 'store'])->middleware('throttle:10,1');

        Route::get('/discipline', [DisciplineController::class, 'index']);
        Route::get('/discipline/files/{file}', [DisciplineController::class, 'file'])->whereNumber('file');
        Route::get('/discipline/{assignment}', [DisciplineController::class, 'show'])->whereNumber('assignment');
        Route::get('/discipline/{assignment}/reports', [DisciplineController::class, 'reports'])->whereNumber('assignment');
        Route::post('/discipline/{assignment}/read', [DisciplineController::class, 'read'])->whereNumber('assignment');
        Route::post('/discipline/{assignment}/submit', [DisciplineController::class, 'submit'])->whereNumber('assignment')->middleware('throttle:20,1');

    });
});
