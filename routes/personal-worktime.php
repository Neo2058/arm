<?php

use App\Http\Controllers\Api\PersonalWorkTimeController;
use App\Http\Middleware\EnsureActiveApiUser;
use Illuminate\Support\Facades\Route;

// Included under either the protected web prefix or the Sanctum mobile prefix.
Route::middleware([EnsureActiveApiUser::class, 'throttle:60,1'])->group(function () {
    Route::get('/month', [PersonalWorkTimeController::class, 'month']);
    Route::get('/settings', [PersonalWorkTimeController::class, 'settings']);
    Route::patch('/settings/{month}', [PersonalWorkTimeController::class, 'updateSettings'])->middleware('throttle:20,1');
    Route::post('/shifts/preview', [PersonalWorkTimeController::class, 'preview']);
    Route::post('/shifts', [PersonalWorkTimeController::class, 'store'])->middleware('throttle:20,1');
    Route::get('/shifts/{shift}', [PersonalWorkTimeController::class, 'show'])->whereNumber('shift');
    Route::post('/shifts/{shift}/preview', [PersonalWorkTimeController::class, 'previewUpdate'])->whereNumber('shift');
    Route::put('/shifts/{shift}', [PersonalWorkTimeController::class, 'update'])->whereNumber('shift')->middleware('throttle:20,1');
});
