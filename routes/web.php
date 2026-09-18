<?php

use App\Http\Controllers\AccessRequestController;
use App\Http\Controllers\AuthController;
use App\Http\Middleware\CheckDeviceBinding;
use App\Http\Middleware\CheckDynamicBarrier;
use App\Http\Middleware\CheckUserExistence;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public
|--------------------------------------------------------------------------
*/

Route::get('/', function () {
    return view('main.welcome');
});

Route::get('/about', [AccessRequestController::class, 'show'])->name('about');
Route::post('/about/request', [AccessRequestController::class, 'submit'])
    ->name('about.request')
    ->middleware('throttle:5,60');

Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->name('login.post');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

/*
|--------------------------------------------------------------------------
| Protected (auth + device + barrier)
| Domain files keep the same names, URIs and extra middleware (signed, instructor, dispatcher).
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', CheckUserExistence::class, CheckDeviceBinding::class, CheckDynamicBarrier::class])->group(function () {
    require __DIR__.'/account.php';
    require __DIR__.'/documents.php';
    require __DIR__.'/training.php';
    require __DIR__.'/work.php';
    require __DIR__.'/journal.php';
    require __DIR__.'/naryad.php';
    require __DIR__.'/support.php';
});
