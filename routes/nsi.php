<?php

use App\Http\Controllers\Nsi\NsiController;
use App\Http\Middleware\EnsureDispatcher;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| НСИ (NSIMENU) — кодификаторы, справочники, нормативы, настройка АРМ
| Только dispatcher. Имена маршрутов nsi.* — для тестов и меню.
|--------------------------------------------------------------------------
*/

Route::prefix('nsi')->middleware(EnsureDispatcher::class)->group(function () {
    Route::get('/', [NsiController::class, 'index'])->name('nsi.index');

    Route::get('/codes', [NsiController::class, 'codes'])->name('nsi.codes');
    Route::get('/codes/{book}', [NsiController::class, 'codesShow'])->name('nsi.codes.show');
    Route::post('/codes/{book}', [NsiController::class, 'codesStore'])->name('nsi.codes.store');
    Route::put('/codes/{book}/{code}', [NsiController::class, 'codesUpdate'])->name('nsi.codes.update');
    Route::delete('/codes/{book}/{code}', [NsiController::class, 'codesDestroy'])->name('nsi.codes.destroy');

    Route::get('/catalogs', [NsiController::class, 'catalogs'])->name('nsi.catalogs');
    Route::get('/signatures', [NsiController::class, 'signatures'])->name('nsi.signatures');
    Route::post('/signatures', [NsiController::class, 'signaturesStore'])->name('nsi.signatures.store');
    Route::put('/signatures/{signature}', [NsiController::class, 'signaturesUpdate'])->name('nsi.signatures.update');
    Route::delete('/signatures/{signature}', [NsiController::class, 'signaturesDestroy'])->name('nsi.signatures.destroy');

    Route::get('/norms', [NsiController::class, 'norms'])->name('nsi.norms');
    Route::get('/norms/hours', [NsiController::class, 'hours'])->name('nsi.hours');
    Route::post('/norms/hours', [NsiController::class, 'hoursStore'])->name('nsi.hours.store');
    Route::put('/norms/hours/{norm}', [NsiController::class, 'hoursUpdate'])->name('nsi.hours.update');
    Route::delete('/norms/hours/{norm}', [NsiController::class, 'hoursDestroy'])->name('nsi.hours.destroy');

    Route::get('/norms/premiums', [NsiController::class, 'premiums'])->name('nsi.premiums');
    Route::post('/norms/premiums', [NsiController::class, 'premiumsStore'])->name('nsi.premiums.store');
    Route::put('/norms/premiums/{premium}', [NsiController::class, 'premiumsUpdate'])->name('nsi.premiums.update');
    Route::delete('/norms/premiums/{premium}', [NsiController::class, 'premiumsDestroy'])->name('nsi.premiums.destroy');

    Route::get('/norms/seniority', [NsiController::class, 'seniority'])->name('nsi.seniority');
    Route::post('/norms/seniority', [NsiController::class, 'seniorityStore'])->name('nsi.seniority.store');
    Route::put('/norms/seniority/{band}', [NsiController::class, 'seniorityUpdate'])->name('nsi.seniority.update');
    Route::delete('/norms/seniority/{band}', [NsiController::class, 'seniorityDestroy'])->name('nsi.seniority.destroy');

    Route::get('/norms/tariffs', [NsiController::class, 'tariffs'])->name('nsi.tariffs');
    Route::post('/norms/tariffs', [NsiController::class, 'tariffsStore'])->name('nsi.tariffs.store');
    Route::put('/norms/tariffs/{tariff}', [NsiController::class, 'tariffsUpdate'])->name('nsi.tariffs.update');
    Route::delete('/norms/tariffs/{tariff}', [NsiController::class, 'tariffsDestroy'])->name('nsi.tariffs.destroy');

    Route::get('/norms/formulas', [NsiController::class, 'formulas'])->name('nsi.formulas');
    Route::put('/norms/formulas/{formula}', [NsiController::class, 'formulasUpdate'])->name('nsi.formulas.update');

    Route::get('/rosters', [NsiController::class, 'rosters'])->name('nsi.rosters');
    Route::post('/rosters/seed', [NsiController::class, 'rostersSeed'])->name('nsi.rosters.seed');
    Route::put('/rosters/{roster}', [NsiController::class, 'rostersUpdate'])->name('nsi.rosters.update');
    Route::delete('/rosters/{roster}', [NsiController::class, 'rostersDestroy'])->name('nsi.rosters.destroy');

    Route::get('/arm', [NsiController::class, 'arm'])->name('nsi.arm');
    Route::get('/arm/users', [NsiController::class, 'users'])->name('nsi.users');
    Route::get('/arm/params', [NsiController::class, 'params'])->name('nsi.params');
    Route::put('/arm/params', [NsiController::class, 'paramsUpdate'])->name('nsi.params.update');
    Route::get('/arm/advance', [NsiController::class, 'advance'])->name('nsi.advance');
    Route::put('/arm/advance', [NsiController::class, 'advanceUpdate'])->name('nsi.advance.update');
    Route::get('/arm/books', [NsiController::class, 'books'])->name('nsi.books');
    Route::post('/arm/books', [NsiController::class, 'booksStore'])->name('nsi.books.store');
    Route::put('/arm/books/{book}', [NsiController::class, 'booksUpdate'])->name('nsi.books.update');
});
