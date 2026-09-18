<?php

use App\Http\Controllers\RospisiController;
use App\Http\Controllers\TrainingController;
use Illuminate\Support\Facades\Route;

Route::get('/teaching', function () {
    return view('teaching.layouts.index');
})->name('teaching.timer');

Route::get('/timer', function () {
    return view('teaching.timer');
})->name('timer');

Route::get('/rosisi', [RospisiController::class, 'index'])->name('rosisi.index');
Route::post('/rosisi/sign/{document}', [RospisiController::class, 'signDocument'])->name('rosisi.sign');
Route::post('/rosisi/log-formular/{task}', [RospisiController::class, 'logFormular'])->name('rosisi.log-formular');
Route::get('/rosisi/statistics', [RospisiController::class, 'statistics'])->name('rosisi.statistics');

Route::get('/admin/serve-training-material', [TrainingController::class, 'adminServeTrainingMaterial'])
    ->name('admin.training-materials.serve')
    ->middleware('signed');

Route::prefix('training')->group(function () {
    Route::get('/', [TrainingController::class, 'index'])->name('training.topics');
    Route::get('/{topic:slug}', [TrainingController::class, 'showTopic'])->name('training.topic');

    Route::get('/video/{material}', [TrainingController::class, 'showVideo'])->name('training.video');
    Route::get('/audio/{material}', [TrainingController::class, 'showAudio'])->name('training.audio');

    Route::get('/video/{material}/stream', [TrainingController::class, 'streamVideo'])
        ->name('training.video.stream')
        ->middleware('signed');
    Route::get('/audio/{material}/stream', [TrainingController::class, 'streamAudio'])
        ->name('training.audio.stream')
        ->middleware('signed');

    Route::post('/{material}/comment', [TrainingController::class, 'storeComment'])->name('training.comment.store');
    Route::post('/{material}/react', [TrainingController::class, 'storeReaction'])->name('training.reaction.store');
});
