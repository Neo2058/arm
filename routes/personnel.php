<?php

use App\Http\Controllers\PersonnelDirectoryController;
use Illuminate\Support\Facades\Route;

Route::get('/personnel', [PersonnelDirectoryController::class, 'index'])->name('personnel.index');
Route::get('/personnel/{personnel}', [PersonnelDirectoryController::class, 'show'])->whereNumber('personnel')->name('personnel.show');
Route::patch('/personnel/{personnel}', [PersonnelDirectoryController::class, 'update'])->whereNumber('personnel')->name('personnel.update');
