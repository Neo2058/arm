<?php

use App\Http\Controllers\ChatAttachmentController;
use Illuminate\Support\Facades\Route;

Route::get('/admin/serve-chat-attachment', [ChatAttachmentController::class, 'adminServe'])
    ->name('admin.chat-attachments.serve')
    ->middleware('signed');
