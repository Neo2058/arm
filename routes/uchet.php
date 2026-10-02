<?php

use App\Http\Controllers\Uchet\UchetController;
use App\Http\Middleware\EnsureUchetStaff;
use Illuminate\Support\Facades\Route;

Route::prefix('uchet')->middleware(EnsureUchetStaff::class)->group(function () {
    Route::get('/', [UchetController::class, 'index'])->name('uchet.index');
    Route::get('/person/{userId}', [UchetController::class, 'person'])->name('uchet.person');
    Route::put('/assignments/{assignment}', [UchetController::class, 'updateHours'])->name('uchet.hours.update');
    Route::post('/generate', [UchetController::class, 'generate'])->name('uchet.generate');
    Route::get('/accounts', [UchetController::class, 'accounts'])->name('uchet.accounts');
    Route::post('/close', [UchetController::class, 'close'])->name('uchet.close');
    Route::post('/reopen', [UchetController::class, 'reopen'])->name('uchet.reopen');
    Route::get('/lsbuh.csv', [UchetController::class, 'lsbuh'])->name('uchet.lsbuh');
    Route::get('/reports', [UchetController::class, 'reports'])->name('uchet.reports');
    Route::get('/extras', [UchetController::class, 'extras'])->name('uchet.extras');
    Route::put('/extras/{userId}', [UchetController::class, 'updateExtras'])->name('uchet.extras.update');
    Route::put('/premiums/{userId}', [UchetController::class, 'updatePremium'])->name('uchet.premiums.update');
});
