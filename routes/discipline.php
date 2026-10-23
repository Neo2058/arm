<?php

use App\Http\Controllers\DisciplineController;
use Illuminate\Support\Facades\Route;

// Included inside the existing auth/active/device/barrier group.
Route::prefix('discipline')->name('discipline.')->group(function () {
    Route::get('/', [DisciplineController::class, 'index'])->name('index');
    Route::get('/files/{file}', [DisciplineController::class, 'download'])->whereNumber('file')->name('files');
    Route::get('/{assignment}', [DisciplineController::class, 'show'])->whereNumber('assignment')->name('show');
    Route::post('/{assignment}/submit', [DisciplineController::class, 'submit'])->whereNumber('assignment')->middleware('throttle:20,1')->name('submit');
    Route::post('/{assignment}/review', [DisciplineController::class, 'review'])->whereNumber('assignment')->name('review');
});
