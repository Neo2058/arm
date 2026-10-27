<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\BarrierController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\TelegramAccountController;
use Illuminate\Support\Facades\Route;

Route::get('/mainMenu', function () {
    if (auth()->user()->canManageDiscipline()) {
        return redirect()->route('filament.discipline.pages.discipline-monitor');
    }

    return view('main.mainMenu');
})->name('mainMenu');

Route::get('/account', [AccountController::class, 'show'])->name('account.show');
Route::patch('/account', [AccountController::class, 'update'])->name('account.update')->middleware('throttle:20,1');

Route::get('/account/telegram', [TelegramAccountController::class, 'show'])->name('telegram.link.show');
Route::post('/account/telegram', [TelegramAccountController::class, 'issue'])->name('telegram.link.issue')->middleware('throttle:5,10');
Route::delete('/account/telegram', [TelegramAccountController::class, 'destroy'])->name('telegram.link.destroy')->middleware('throttle:5,10');

Route::get('/barrier', [BarrierController::class, 'show'])->name('barrier.show');
Route::post('/api/barrier/verify', [BarrierController::class, 'verify'])->name('barrier.verify')->middleware('throttle:barrier');

Route::get('/device-register', [DeviceController::class, 'showRegisterForm'])->name('device.register.form');
Route::post('/api/device/register', [DeviceController::class, 'registerDevice'])->name('device.register.submit')->middleware('throttle:5,60');
