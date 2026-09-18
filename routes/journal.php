<?php

use App\Http\Controllers\TCHMJournalController;
use App\Http\Middleware\EnsureInstructor;
use Illuminate\Support\Facades\Route;

Route::middleware(EnsureInstructor::class)->prefix('journal')->group(function () {
    Route::get('/', [TCHMJournalController::class, 'index'])->name('journal.index');
    Route::post('/todo', [TCHMJournalController::class, 'addTodo'])->name('journal.todo.add');
    Route::post('/todo/{id}/complete', [TCHMJournalController::class, 'completeTodo'])->name('journal.todo.complete');
    Route::post('/todo/{id}/update', [TCHMJournalController::class, 'updateTodo'])->name('journal.todo.update');
    Route::delete('/todo/{id}', [TCHMJournalController::class, 'deleteTodo'])->name('journal.todo.destroy');
    Route::post('/document', [TCHMJournalController::class, 'uploadDocument'])->name('journal.document.upload');
    Route::post('/ask', [TCHMJournalController::class, 'askDocument'])->name('journal.ask');

    Route::get('/settings', [TCHMJournalController::class, 'settings'])->name('journal.settings');
    Route::post('/settings', [TCHMJournalController::class, 'updateSettings'])->name('journal.settings.update');
    Route::get('/standards', [TCHMJournalController::class, 'standards'])->name('journal.standards');
    Route::post('/standards', [TCHMJournalController::class, 'updateStandards'])->name('journal.standards.update');
    Route::get('/history', [TCHMJournalController::class, 'history'])->name('journal.history');
    Route::get('/report', [TCHMJournalController::class, 'report'])->name('journal.report');
    Route::post('/report/vacation', [TCHMJournalController::class, 'addVacation'])->name('journal.report.vacation.add');
    Route::delete('/report/vacation/{id}', [TCHMJournalController::class, 'deleteVacation'])->name('journal.report.vacation.delete');
});
