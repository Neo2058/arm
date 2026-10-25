<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Services\TelegramAccountLinker;
use App\Services\TelegramBotApi;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TelegramAccountController extends Controller
{
    public function show(Request $request, TelegramBotApi $api)
    {
        $username = $this->username();

        return $this->json([
            'linked' => DB::table('telegram_accounts')->where('user_id', $request->user()->id)->exists(),
            'available' => $api->configured('key') && $username !== null,
            'bot_url' => $username ? 'https://t.me/'.$username : null,
        ]);
    }

    public function issue(Request $request, TelegramBotApi $api, TelegramAccountLinker $links)
    {
        $username = $this->username();
        abort_unless($api->configured('key') && $username !== null, 503);
        $token = $links->issue($request->user());

        return $this->json(['url' => 'https://t.me/'.$username.'?start='.$token, 'expires_in' => 600]);
    }

    public function destroy(Request $request, TelegramAccountLinker $links)
    {
        $links->unlink($request->user());

        return $this->json(['linked' => false]);
    }

    private function username(): ?string
    {
        $username = config('services.telegram.key_bot_username');

        return is_string($username) && preg_match('/^[A-Za-z0-9_]{5,32}$/D', $username) ? $username : null;
    }

    private function json(array $data)
    {
        return response()->json($data)->header('Cache-Control', 'no-store, private');
    }
}
