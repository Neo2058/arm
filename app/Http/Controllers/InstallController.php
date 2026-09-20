<?php

namespace App\Http\Controllers;

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
