<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class InstallController extends Controller
{
    public function show(Request $request)
    {
        $ua = strtolower($request->userAgent() ?? '');

        return view('main.install', [
            'androidReady' => is_file($this->androidPath()),
            'iosReady' => is_file($this->iosPath()),
            'isAndroid' => str_contains($ua, 'android'),
            'isIos' => str_contains($ua, 'iphone') || str_contains($ua, 'ipad') || str_contains($ua, 'ipod'),
        ]);
    }

    public function android(): BinaryFileResponse
    {
        $path = $this->androidPath();
        abort_unless(is_file($path), 404, 'Сборка Android ещё не выложена.');

        return response()->download($path, 'tch15.apk', [
            'Content-Type' => 'application/vnd.android.package-archive',
        ]);
    }

    public function androidManifest(): JsonResponse
    {
        $path = $this->androidPath();
        $meta = public_path('downloads/android-latest.json');
        abort_unless(is_file($path) && is_file($meta), 404, 'Сборка Android ещё не выложена.');
        $data = json_decode((string) file_get_contents($meta), true);
        abort_unless(is_array($data) && ($data['package'] ?? '') === 'ru.tch15.arm' && (int) ($data['version_code'] ?? 0) >= 1, 404);
        $payload = [
            'package' => 'ru.tch15.arm',
            'version' => (string) ($data['version'] ?? ''),
            'version_code' => (int) $data['version_code'],
            'sha256' => (string) ($data['sha256'] ?? ''),
            'size' => filesize($path),
            'url' => '/install/android',
        ];
        if (! empty($data['published_at'])) {
            $payload['published_at'] = $data['published_at'];
        }

        return response()->json($payload)->header('Cache-Control', 'no-store');
    }

    public function ios()
    {
        abort_unless(is_file($this->iosPath()), 404, 'Сборка iOS ещё не выложена.');

        $plist = url('/install/ios.plist');

        return redirect('itms-services://?action=download-manifest&url='.rawurlencode($plist));
    }

    public function iosPlist()
    {
        abort_unless(is_file($this->iosPath()), 404, 'Сборка iOS ещё не выложена.');

        $ipaUrl = url('/install/ios.ipa');

        return response()
            ->view('main.ios-manifest', [
                'ipaUrl' => $ipaUrl,
                'bundleId' => 'ru.tch15.arm',
                'title' => 'ТЧ-15',
                'version' => '1.0.0',
            ])
            ->header('Content-Type', 'application/xml');
    }

    public function iosIpa(): BinaryFileResponse
    {
        $path = $this->iosPath();
        abort_unless(is_file($path), 404, 'Сборка iOS ещё не выложена.');

        return response()->download($path, 'tch15.ipa', [
            'Content-Type' => 'application/octet-stream',
        ]);
    }

    private function androidPath(): string
    {
        return public_path('downloads/tch15-android.apk');
    }

    private function iosPath(): string
    {
        return public_path('downloads/tch15-ios.ipa');
    }
}
