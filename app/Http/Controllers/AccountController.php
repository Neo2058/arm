<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AccountController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user();
        $user->loadMissing('profile');

        return response()->view('account.show', [
            'user' => $user,
            'profile' => $user->profile,
            'roleLabel' => UserRole::safeFrom($user->roleValue())->label(),
            'telegramLinked' => DB::table('telegram_accounts')->where('user_id', $user->id)->exists(),
        ])->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }
}
