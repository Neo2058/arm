<?php

namespace App\Http\Controllers;
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

            // Логируем вход в ClickHouse
            // ClickHouseService::log(Auth::user(), 'login');

            return redirect()->intended('/mainMenu');
        }

        return back()->withErrors([
            'email' => 'Неверные учётные данные, пользователь не существует.',
        ])->onlyInput('email');
    }

    public function logout(Request $request) : Redirector {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect('/');
    }
}
