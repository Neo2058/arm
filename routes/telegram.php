<?php

use App\Http\Controllers\TelegramKeyBotController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PasswordRecoveryController;

Route::get('/password/forgot', [PasswordRecoveryController::class, 'show'])->name('password.request');
Route::get('/password/reset', [PasswordRecoveryController::class, 'form'])->name('password.reset');
Route::post('/password/reset', [PasswordRecoveryController::class, 'reset'])->middleware('throttle:10,1')->name('password.update');

// Public transport endpoint. Controller verifies Telegram's secret before processing input.
Route::post('/telegram/webhook', [TelegramKeyBotController::class, 'webhook'])->name('telegram.key.webhook');
