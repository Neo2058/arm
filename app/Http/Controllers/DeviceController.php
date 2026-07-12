<?php


namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\UserDevice;
use App\Services\AdminNotificationService;
use App\Services\TelegramService;
use App\Services\ClickHouseService;
use Illuminate\Support\Facades\Auth;

class DeviceController extends Controller
{
    public function showRegisterForm()
    {
        // Если устройство пользователя уже подтверждено, незачем ему тут находиться
        $deviceKey = request()->cookie('device_key');
        if ($deviceKey && Auth::user()->devices()->where('device_key', $deviceKey)->where('is_approved', true)->exists()) {
            return redirect()->route('mainMenu');
        }

        return view('auth.register-device');
    }

    public function registerDevice(Request $request)
    {
        $request->validate([
            'device_key' => 'required|string',
            'device_name' => 'required|string|max:255',
        ]);

        $user = Auth::user();
        $deviceKey = $request->input('device_key');
        $deviceName = $request->input('device_name');
        $userAgent = $request->userAgent();
        $os = $this->detectOS($userAgent);

        // ПРОВЕРКА НА СПАМ: Ищем существующую заявку для этого устройства
        $existingDevice = UserDevice::where('user_id', $user->id)
            ->where('device_key', $deviceKey)
            ->first();

        if ($existingDevice) {
            if ($existingDevice->is_approved) {
                return redirect()->route('mainMenu');
            }
            // Если заявка уже на модерации — мягко уведомляем пользователя, не отправляя спам в Telegram
            return redirect()->route('device.register.form')->with('success', 'Заявка для этого устройства уже находится на рассмотрении у инструктора. Повторный запрос не требуется.');
        }

        // Если заявки нет — создаем новую
        $device = UserDevice::create([
            'user_id' => $user->id,
            'device_key' => $deviceKey,
            'device_name' => $deviceName,
            'os' => $os,
            'is_approved' => false,
        ]);

        // Уведомления — не должны ломать регистрацию устройства (оборачиваем)
        try {
            $msg = "📱 *НОВАЯ ЗАЯВКА НА ПРИВЯЗКУ УСТРОЙСТВА*\n\n";
            $msg .= "👤 Сотрудник: *{$user->name}*\n";
            $msg .= "🛠️ Устройство: *{$deviceName}*\n";
            $msg .= "🔑 Ключ: `{$deviceKey}`\n";
            $msg .= "🌐 IP: " . $request->ip();

            TelegramService::send($msg);
            ClickHouseService::log('device_request', 0, "Заявка на устройство: {$deviceName}");

            AdminNotificationService::notify(
                'device_request',
                'Новая заявка на привязку устройства',
                "Сотрудник: {$user->name}\nУстройство: {$deviceName}\nКлюч: {$deviceKey}\nIP: {$request->ip()}",
                [
                    'user_id' => $user->id,
                    'device_name' => $deviceName,
                    'device_key' => $deviceKey,
                    'ip' => $request->ip(),
                ]
            );
        } catch (\Throwable $e) {
            \Log::warning('Device registration notification failed (non-fatal)', ['error' => $e->getMessage()]);
        }

        // Используем явный редирект на форму (надёжнее back() после POST к /api/... пути)
        return redirect()->route('device.register.form')->with('success', 'Заявка успешно отправлена инструктору. Ожидайте подтверждения доступа.');
    }

    private function detectOS(string $userAgent): string
    {
        $ua = strtolower($userAgent);
        if (strpos($ua, 'iphone') !== false || strpos($ua, 'ipad') !== false || strpos($ua, 'ipod') !== false) {
            return 'ios';
        }
        if (strpos($ua, 'android') !== false) {
            return 'android';
        }
        return 'other';
    }
}
