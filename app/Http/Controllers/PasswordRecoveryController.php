<?php

namespace App\Http\Controllers;

use App\Services\TelegramPasswordReset;
use App\Services\TelegramService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

class PasswordRecoveryController extends Controller
{
    public function show()
    {
        $username = config('services.telegram.key_bot_username');

        return $this->page([
            'botUrl' => is_string($username) && preg_match('/^[A-Za-z0-9_]{5,32}$/D', $username)
                ? 'https://t.me/'.$username.'?start=recover' : null,
        ]);
    }

    public function form()
    {
        return $this->page(['resetForm' => true]);
    }

    public function reset(Request $request, TelegramPasswordReset $resets)
    {
        // Do not redirect validation input into session flash, especially the reset token.
        $validator = validator($request->only('token', 'password', 'password_confirmation'), [
            'token' => ['required', 'string', 'regex:/^[A-Za-z0-9]{64}$/D'],
            'password' => ['bail', 'required', 'string', 'max:72', 'confirmed', Password::min(12)->letters()->numbers(),
                function ($attribute, $value, $fail) {
                    if (strlen($value) > 72) {
                        $fail('Пароль должен занимать не более 72 байт.');
                    }
                }],
        ]);
        if ($validator->fails()) {
            return $this->page(['resetForm' => true, 'failure' => 'Проверьте ссылку и пароль: не менее 12 символов, буквы и цифры. Пароли должны совпадать. Откройте ссылку из бота заново.'], 422);
        }
        $user = $resets->reset($request->string('token')->toString(), $request->string('password')->toString());
        if (! $user) {
            return $this->page(['failure' => 'Ссылка недействительна или истекла. Запросите новую командой /reset в боте.'], 422);
        }
        TelegramService::send('Восстановление пароля ARM завершено. Пользователь ID '.$user->id.'. Прежние сеансы и мобильные токены отозваны.', null, '');

        return $this->page(['success' => true]);
    }

    private function page(array $data, int $status = 200)
    {
        return response()->view('auth.password-recovery', $data, $status)
            ->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer')
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; script-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
    }
}
