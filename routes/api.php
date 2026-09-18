<?php

use App\Http\Controllers\Api\Mobile\AuthController;
use App\Http\Controllers\Api\Mobile\DocumentController;
use App\Http\Controllers\Api\Mobile\FileController;
use App\Http\Controllers\Api\Mobile\TrainingController;
use Illuminate\Support\Facades\Route;

Route::prefix('mobile')->group(function () {
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

    Route::get('/files/documents/{document}', [FileController::class, 'document'])
        ->name('mobile.files.documents')
        ->middleware('signed');
    Route::get('/files/training/{material}', [FileController::class, 'training'])
        ->name('mobile.files.training')
        ->middleware('signed');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);

        Route::get('/documents', [DocumentController::class, 'index']);
        Route::get('/documents/{document}', [DocumentController::class, 'show']);

        Route::get('/training/topics', [TrainingController::class, 'topics']);
        Route::get('/training/topics/{topic:slug}', [TrainingController::class, 'topic']);
        Route::get('/training/materials/{material}', [TrainingController::class, 'material']);
        Route::post('/training/materials/{material}/comments', [TrainingController::class, 'storeComment']);
    });
});
