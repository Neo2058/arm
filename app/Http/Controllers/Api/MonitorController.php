<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ClickHouseService;
use App\Services\MonitorSnapshot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class MonitorController extends Controller
{
    public function login(Request $request)
    {
        $data = $request->validate(['email' => 'required|email', 'password' => 'required|string|max:1024']);
        $user = User::where('email', $data['email'])->first();
        // Always perform a password check, including unknown accounts.
        $valid = Hash::check($data['password'], $user?->password ?? '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.');
        abort_unless($valid && $user?->is_active && $user->isSuperAdmin(), 422, 'Вход недоступен. Проверьте данные и права доступа.');
        $user->tokens()->where('name', 'monitor')->delete();
        $token = $user->createToken('monitor', ['monitor:read'], now()->addHours(12))->plainTextToken;
        ClickHouseService::log('monitor.login', $user->id, ['result' => 'success'], $user);

        return response()->json(['token' => $token])->header('Cache-Control', 'no-store');
    }

    public function snapshot(MonitorSnapshot $snapshot)
    {
        return response()->json($snapshot->read());
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        ClickHouseService::log('monitor.logout', $request->user()->id, ['result' => 'success'], $request->user());

        return response()->json(['ok' => true]);
    }

    public function installerInfo()
    {
        $path = config('monitor.installer_path');
        abort_unless(is_file($path), 503, 'Установщик ещё не опубликован.');

        return response()->json(['bytes' => filesize($path), 'sha256' => hash_file('sha256', $path)]);
    }

    public function installer(Request $request)
    {
        $path = config('monitor.installer_path');
        abort_unless(is_file($path), 503, 'Установщик ещё не опубликован.');
        ClickHouseService::log('monitor.installer_downloaded', $request->user()->id, ['result' => 'authorized'], $request->user());

        return response()->download($path, 'tch15-monitor.apk', ['Content-Type' => 'application/vnd.android.package-archive']);
    }
}
