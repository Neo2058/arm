<?php

use App\Http\Controllers\Naryad\BreakdownsController;
use App\Http\Controllers\Naryad\CatalogsController;
use App\Http\Controllers\Naryad\PersonnelController;
use App\Http\Controllers\Naryad\PlanningController;
use App\Http\Controllers\Naryad\PrintController;
use App\Http\Controllers\Naryad\SetkaController;
use App\Http\Middleware\EnsureDispatcher;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Планирование наряда для Нарядчика
| Только dispatcher. Имена маршрутов не менять — на них завязан JS в layout.
|--------------------------------------------------------------------------
*/

Route::prefix('naryad')->middleware(EnsureDispatcher::class)->group(function () {
    Route::get('/', [PlanningController::class, 'index'])->name('naryad.index');

    Route::get('/partial/setka', [SetkaController::class, 'partialSetka'])->name('naryad.partial.setka');
    Route::get('/partial/print', [PrintController::class, 'partial'])->name('naryad.partial.print');
    Route::get('/print', [PrintController::class, 'sheet'])->name('naryad.print');
    Route::post('/assign', [SetkaController::class, 'assign'])->name('naryad.assign');
    Route::post('/unassign', [SetkaController::class, 'unassign'])->name('naryad.unassign');
    Route::post('/podstroika-limit', [SetkaController::class, 'savePodstroikaLimit'])->name('naryad.podstroika-limit.save');

    Route::get('/partial/crews', [CatalogsController::class, 'partialCrews'])->name('naryad.partial.crews');
    Route::get('/partial/variants', [CatalogsController::class, 'partialVariants'])->name('naryad.partial.variants');
    Route::get('/partial/calendar', [CatalogsController::class, 'partialCalendar'])->name('naryad.partial.calendar');
    Route::get('/partial/types', [CatalogsController::class, 'partialTypes'])->name('naryad.partial.types');
    Route::get('/partial/users', [CatalogsController::class, 'partialUsers'])->name('naryad.partial.users');
    Route::get('/partial/deviations', [CatalogsController::class, 'partialDeviations'])->name('naryad.partial.deviations');
    Route::get('/partial/norms', [CatalogsController::class, 'partialNorms'])->name('naryad.partial.norms');

    Route::post('/user-profile/{profile}/flags', [CatalogsController::class, 'updateUserFlags'])
        ->name('naryad.user-profile.update-flags');

    Route::post('/crews', [CatalogsController::class, 'storeCrew'])->name('naryad.crews.store');
    Route::get('/crews', fn () => redirect()->route('naryad.partial.crews'));

    Route::post('/types', [CatalogsController::class, 'storeType'])->name('naryad.types.store');
    Route::put('/types/{type}', [CatalogsController::class, 'updateType'])->name('naryad.types.update');
    Route::delete('/types/{type}', [CatalogsController::class, 'destroyType'])->name('naryad.types.destroy');
    Route::get('/types', fn () => redirect()->route('naryad.partial.types'));

    Route::post('/variants', [CatalogsController::class, 'storeVariant'])->name('naryad.variants.store');
    Route::put('/variants/{variant}', [CatalogsController::class, 'updateVariant'])->name('naryad.variants.update');
    Route::delete('/variants/{variant}', [CatalogsController::class, 'destroyVariant'])->name('naryad.variants.destroy');
    Route::get('/variants', fn () => redirect()->route('naryad.partial.variants'));

    Route::post('/deviations', [CatalogsController::class, 'storeDeviation'])->name('naryad.deviations.store');
    Route::put('/deviations/{deviation}', [CatalogsController::class, 'updateDeviation'])->name('naryad.deviations.update');
    Route::delete('/deviations/{deviation}', [CatalogsController::class, 'destroyDeviation'])->name('naryad.deviations.destroy');
    Route::get('/deviations', fn () => redirect()->route('naryad.partial.deviations'));

    Route::post('/calendar', [CatalogsController::class, 'saveCalendar'])->name('naryad.calendar.save');

    Route::post('/norms', [CatalogsController::class, 'updateNorm'])->name('naryad.norms.update');
    Route::post('/extra-conditions', [CatalogsController::class, 'storeExtraCondition'])->name('naryad.extra_conditions.store');

    Route::get('/partial/breakdowns', [BreakdownsController::class, 'partialBreakdowns'])->name('naryad.partial.breakdowns');
    Route::put('/breakdowns/{breakdown}', [BreakdownsController::class, 'updateBreakdown'])->name('naryad.breakdowns.update');
    Route::get('/partial/holidays', [BreakdownsController::class, 'partialHolidays'])->name('naryad.partial.holidays');
    Route::post('/holidays', [BreakdownsController::class, 'storeHoliday'])->name('naryad.holidays.store');
    Route::delete('/holidays/{holiday}', [BreakdownsController::class, 'destroyHoliday'])->name('naryad.holidays.destroy');

    Route::get('/partial/personnel', [PersonnelController::class, 'partialPersonnel'])->name('naryad.partial.personnel');
    Route::get('/partial/appointments', [PersonnelController::class, 'partialAppointments'])->name('naryad.partial.appointments');
    Route::post('/appointments', [PersonnelController::class, 'storeAppointment'])->name('naryad.appointments.store');
    Route::delete('/appointments/{appointment}', [PersonnelController::class, 'destroyAppointment'])->name('naryad.appointments.destroy');
    Route::get('/partial/absences', [PersonnelController::class, 'partialAbsences'])->name('naryad.partial.absences');
    Route::post('/absences', [PersonnelController::class, 'storeAbsence'])->name('naryad.absences.store');
    Route::delete('/absences/{absence}', [PersonnelController::class, 'destroyAbsence'])->name('naryad.absences.destroy');
    Route::put('/extra-conditions/{extra}', [CatalogsController::class, 'updateExtraCondition'])->name('naryad.extra_conditions.update');
    Route::delete('/extra-conditions/{extra}', [CatalogsController::class, 'destroyExtraCondition'])->name('naryad.extra_conditions.destroy');
});
