<?php

namespace App\Http\Controllers\Naryad;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Support\Facades\Auth;

class PlanningController extends Controller implements HasMiddleware
{
    use EnsuresDispatcher;

    /**
     * Главная страница — оболочка с уникальным сайдбаром.
     * Начальный контент — «Сетка».
     */
    public function index()
    {
        $this->abortIfNotDispatcher();

        $currentMonth = Carbon::now()->startOfMonth();
        $month = request('month', $currentMonth->format('Y-m'));

        return view('naryad.index', [
            'currentMonth' => $month,
            'user' => Auth::user(),
        ]);
    }
}
