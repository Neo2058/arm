<?php

use App\Http\Controllers\TelegramKeyBotController;
use Illuminate\Support\Facades\Route;

// Public transport endpoint. Controller verifies Telegram's secret before processing input.
Route::post('/telegram/webhook', [TelegramKeyBotController::class, 'webhook'])->name('telegram.key.webhook');
