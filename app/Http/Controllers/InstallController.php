<?php

namespace App\Http\Controllers;

use App\Services\Install\AndroidReleaseMetadata;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class InstallController extends Controller
{
    public function show(Request $request)
    {
        $ua = strtolower($request->userAgent() ?? '');

        return view('main.install', [
            'androidReady' => is_file($this->androidPath()),
            'androidRelease' => $this->androidRelease(),
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
        $release = $this->androidRelease();
        abort_unless($release !== null, 404, 'Сборка Android ещё не выложена.');

        return response()->json($release)->header('Cache-Control', 'no-store');
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

    private function androidRelease(): ?array
    {
        $path = $this->androidPath();
        $meta = public_path('downloads/android-latest.json');
        if (! is_file($path) || ! is_file($meta)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($meta), true);
        if (! is_array($data) || ($data['package'] ?? '') !== AndroidReleaseMetadata::PACKAGE || (int) ($data['version_code'] ?? 0) < 1) {
            return null;
        }
        $declared = AndroidReleaseMetadata::sha256($data['sha256'] ?? '');
        $actual = $this->fileSha256($path);
        $signature = AndroidReleaseMetadata::signature($data['signature'] ?? '');
        if ($declared === '' || $actual === '' || $declared !== $actual || $signature === '') {
            return null;
        }
        $fields = AndroidReleaseMetadata::fields([
            'package' => AndroidReleaseMetadata::PACKAGE,
            'version' => (string) ($data['version'] ?? ''),
            'version_code' => (int) $data['version_code'],
            'sha256' => $actual,
            'size' => filesize($path),
            'url' => AndroidReleaseMetadata::URL,
            'certificate_sha256' => $data['certificate_sha256'] ?? '',
            'changes' => $data['changes'] ?? [],
        ]);
        $message = $fields ? AndroidReleaseMetadata::canonical($fields) : null;
        if ($message === null || ! AndroidReleaseMetadata::verify($message, $signature)) {
            return null;
        }

        return $fields + ['signature' => $signature];
    }

    private function fileSha256(string $path): string
    {
        $mtime = (int) @filemtime($path);
        $size = (int) @filesize($path);
        if ($mtime < 1 || $size < 1) {
            return '';
        }
        $key = 'install-apk-sha256:'.$mtime.':'.$size;
        $cached = Cache::get($key);
        if (is_string($cached) && preg_match('/^[a-f0-9]{64}$/D', $cached)) {
            return $cached;
        }
        $value = @hash_file('sha256', $path);
        $digest = is_string($value) ? strtolower($value) : '';
        if (! preg_match('/^[a-f0-9]{64}$/D', $digest)) {
            return '';
        }
        Cache::put($key, $digest, 86400);

        return $digest;
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
