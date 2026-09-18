<?php

use App\Http\Controllers\BarrierController;
use App\Http\Controllers\DeviceController;
use Illuminate\Support\Facades\Route;

Route::get('/mainMenu', function () {
    return view('main.mainMenu');
})->name('mainMenu');

Route::get('/barrier', [BarrierController::class, 'show'])->name('barrier.show');
Route::post('/api/barrier/verify', [BarrierController::class, 'verify'])->name('barrier.verify');

Route::get('/device-register', [DeviceController::class, 'showRegisterForm'])->name('device.register.form');
Route::post('/api/device/register', [DeviceController::class, 'registerDevice'])->name('device.register.submit');
