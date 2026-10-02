<?php

use App\Http\Controllers\Api\WorkShiftController;
use App\Http\Controllers\NaryadViewerController;
use App\Http\Controllers\PodstroikiController;
use App\Models\QuizResult;
use Illuminate\Support\Facades\Route;

Route::get('/naryady', [NaryadViewerController::class, 'index'])->name('naryady.index');
Route::post('/naryady/search-people', [NaryadViewerController::class, 'searchPeople'])->name('naryady.search-people');
Route::get('/naryady/{naryad}', [NaryadViewerController::class, 'show'])->name('naryady.show');
Route::get('/naryady/{naryad}/search', [NaryadViewerController::class, 'search'])->name('naryady.search');

Route::get('/api/work-shifts', [WorkShiftController::class, 'index']);
Route::post('/api/work-shifts', [WorkShiftController::class, 'store']);
Route::post('/api/work-shifts/preview', [WorkShiftController::class, 'preview']);

Route::get('/worktime', function () {
    $unreadResultsCount = QuizResult::where('user_id', auth()->id())
        ->where('is_viewed', false)
        ->count();

    return view('worktime.worktime', [
        'unreadCount' => $unreadResultsCount,
    ]);
})->name('work.time.index');

Route::get('/podstroiki', [PodstroikiController::class, 'index'])->name('podstroiki.index');
Route::post('/podstroiki', [PodstroikiController::class, 'store'])->name('podstroiki.store');
Route::post('/podstroiki/{podstroika}/status', [PodstroikiController::class, 'updateStatus'])->name('podstroiki.update-status');
