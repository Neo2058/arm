<?php

namespace App\Http\Controllers;

use App\Services\TelegramAccountLinker;
use App\Services\TelegramBotApi;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TelegramAccountController extends Controller
{
    public function show(Request $request)
    {
        return response()->view('auth.telegram', [
            'account' => DB::table('telegram_accounts')->where('user_id', $request->user()->id)->first(),
            'available' => app(TelegramBotApi::class)->configured('key') && filled(config('services.telegram.key_bot_username')),
        ])->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }

    public function issue(Request $request, TelegramAccountLinker $links, TelegramBotApi $api)
    {
        $username = config('services.telegram.key_bot_username');
        abort_unless($api->configured('key') && is_string($username) && preg_match('/^[A-Za-z0-9_]{5,32}$/D', $username), 503);
        $token = $links->issue($request->user());

        return redirect()->route('telegram.link.show')->with('telegram_link_url', 'https://t.me/'.$username.'?start='.$token);
    }

    public function destroy(Request $request, TelegramAccountLinker $links)
    {
        $links->unlink($request->user());

        return redirect()->route('telegram.link.show')->with('success', 'Telegram отвязан.');
    }
}
