<?php

namespace App\Http\Controllers;

use App\Services\ClickHouseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function showLogin(): View
    {
        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (Auth::attempt($credentials, $request->has('remember'))) {
            $request->session()->regenerate();
            $request->session()->flash('show_welcome_modal', true);
            ClickHouseService::log('login'); // Логируем вход

            $user = Auth::user();

            if ($user->isDispatcher()) {
                // Нарядчик попадает сразу в инструмент планирования наряда (с собственным сайдбаром)
                return redirect()->route('naryad.index');
            }

            return redirect()->intended('mainMenu');
        }

        return back()->withErrors([
            'email' => 'Неверные учётные данные, пользователь не существует.',
        ])->onlyInput('email');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // Перенаправляем на именованный маршрут логина
        return redirect()->route('login');
    }
}
