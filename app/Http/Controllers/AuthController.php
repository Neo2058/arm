<?php

namespace App\Http\Controllers;
use App\Services\ClickHouseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function showLogin() : View {
        return view('auth.login');
    }

    public function login(Request $request) : RedirectResponse{
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
            ]);

        if (Auth::attempt($credentials, $request->has('remember'))) {
            $request->session()->regenerate();
            ClickHouseService::log('login'); // Логируем вход
            return redirect()->intended('mainMenu');
        }

        return back()->withErrors([
            'email' => 'Неверные учётные данные, пользователь не существует.',
        ])->onlyInput('email');
    }

public function logout(Request $request): RedirectResponse
{
    \Illuminate\Support\Facades\Auth::logout();

    $request->session()->invalidate();
    $request->session()->regenerateToken();

    // Перенаправляем на именованный маршрут логина
    return redirect()->route('login');
}
}
