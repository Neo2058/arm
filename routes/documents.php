<?php

use App\Http\Controllers\Api\QuizResultController;
use App\Http\Controllers\DocumentController;
use App\Models\Quiz;
use Illuminate\Support\Facades\Route;

Route::get('/documents', [DocumentController::class, 'index'])->name('documents.index');
Route::get('/api/documents/{document}/click', [DocumentController::class, 'show']);

Route::get('/documents/{document}/file', [DocumentController::class, 'serveFile'])
    ->name('documents.file')
    ->middleware('signed');

Route::get('/documents/{document}/download', [DocumentController::class, 'downloadFile'])
    ->name('documents.download');

Route::get('/admin/serve-document', [DocumentController::class, 'adminServeDocument'])
    ->name('admin.documents.serve')
    ->middleware('signed');

Route::get('/results-history', [DocumentController::class, 'history'])->name('quiz.results.history');

Route::get('/quiz/{quiz}', function (Quiz $quiz) {
    $quizData = $quiz->load('questions.answers');

    return view('quiz.show', [
        'quiz' => $quizData,
    ]);
})->name('quiz.show');

Route::get('/general-quiz', [DocumentController::class, 'showGeneralQuiz'])->name('quiz.general');

Route::post('/api/quiz-results', [QuizResultController::class, 'store'])->name('quiz-results.store');
