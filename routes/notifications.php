<?php

use App\Http\Controllers\NotificationController;
use Illuminate\Support\Facades\Route;

Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
Route::get('/notifications/inbox.json', [NotificationController::class, 'inbox'])
    ->name('notifications.inbox')
    ->middleware('throttle:60,1,web-notify-inbox');
Route::post('/notifications/{notification}/read', [NotificationController::class, 'read'])
    ->whereNumber('notification')
    ->name('notifications.read')
    ->middleware('throttle:60,1,web-notify-read');
Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])
    ->name('notifications.read-all')
    ->middleware('throttle:20,1,web-notify-read-all');
Route::post('/notifications/push-subscription', [NotificationController::class, 'subscribe'])
    ->name('notifications.subscribe')
    ->middleware('throttle:20,1,web-push-sub');
Route::delete('/notifications/push-subscription', [NotificationController::class, 'unsubscribe'])
    ->name('notifications.unsubscribe')
    ->middleware('throttle:20,1,web-push-unsub');
