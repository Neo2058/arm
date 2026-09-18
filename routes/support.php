<?php

use App\Http\Controllers\ActionLogController;
use App\Http\Controllers\BackstageController;
use App\Http\Controllers\BugReportController;
use App\Http\Controllers\TelegramKeyBotController;
use Illuminate\Support\Facades\Route;

Route::post('/telegram/webhook', [TelegramKeyBotController::class, 'webhook']);

Route::post('/api/bug-report', [BugReportController::class, 'store'])->name('bug.report.store');
Route::post('/api/actions/log', [ActionLogController::class, 'store'])->name('actions.log');

Route::get('/backstage', [BackstageController::class, 'index'])->name('backstage.index');
Route::post('/backstage', [BackstageController::class, 'store'])->name('backstage.store');
Route::post('/backstage/support', [BackstageController::class, 'support'])->name('backstage.support');

Route::post('/webhooks/yookassa', [BackstageController::class, 'yookassaWebhook'])->name('webhooks.yookassa');
