<?php

namespace Tests\Feature;

use App\Services\Install\AndroidReleaseMetadata;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class InstallPageTest extends TestCase
{
    private string $temporaryPublicPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->temporaryPublicPath = sys_get_temp_dir().'/arm-install-test-'.bin2hex(random_bytes(8));
        File::makeDirectory($this->temporaryPublicPath, 0755, true);
        $this->app->usePublicPath($this->temporaryPublicPath);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->temporaryPublicPath);
        parent::tearDown();
    }

    public function test_install_page_renders(): void
    {
        $this->get('/install')
            ->assertOk()
            ->assertSee('ТЧ-15 на телефон')
            ->assertSee('Android')
            ->assertSee('iPhone')
            ->assertSee('Ссылка для скачивания')
            ->assertSee('тч-15.рф/install')
            ->assertDontSee('eas build')
            ->assertDontSee('public/downloads')
            ->assertDontSee('/api/mobile');
    }

    public function test_missing_binaries_return_404(): void
    {
        $this->get('/install/android')->assertNotFound();
        $this->get('/install/android.json')->assertNotFound();
        $this->get('/install/ios')->assertNotFound();
        $this->get('/install/ios.plist')->assertNotFound();
        $this->get('/install/ios.ipa')->assertNotFound();
    }

    public function test_android_apk_is_downloadable_when_present(): void
    {
        $dir = public_path('downloads');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $path = $dir.'/tch15-android.apk';
        $bytes = 'apk-bytes';
        $sha = hash('sha256', $bytes);
        file_put_contents($path, $bytes);

        try {
            $this->get('/install/android')
                ->assertOk()
                ->assertHeader('content-type', 'application/vnd.android.package-archive');
            $this->getJson('/install/android.json')->assertNotFound();
            file_put_contents($dir.'/android-latest.json', json_encode([
                'package' => 'ru.tch15.arm',
                'version' => '1.1.12',
                'version_code' => 28,
                'sha256' => str_repeat('ab', 32),
                'size' => 1,
                'url' => '/install/android',
                'publisher_git_commit' => 'should-not-leak',
                'changes' => ['Дни недели в календаре'],
            ]));
            $this->getJson('/install/android.json')->assertNotFound();
            file_put_contents($dir.'/android-latest.json', json_encode($this->signedManifest([
                'package' => 'ru.tch15.arm',
                'version' => '1.1.12',
                'version_code' => 28,
                'sha256' => $sha,
                'size' => strlen($bytes),
                'url' => '/install/android',
                'certificate_sha256' => AndroidReleaseMetadata::CERTIFICATE,
                'publisher_git_commit' => 'should-not-leak',
                'changes' => ['Дни недели в календаре', str_repeat('я', 200), "\n", 4, '<img src=x onerror=1>'],
            ])));
            $this->getJson('/install/android.json')
                ->assertOk()
                ->assertHeader('Cache-Control', 'no-store, private')
                ->assertJsonPath('package', 'ru.tch15.arm')
                ->assertJsonPath('version_code', 28)
                ->assertJsonPath('size', strlen($bytes))
                ->assertJsonPath('sha256', $sha)
                ->assertJsonPath('certificate_sha256', AndroidReleaseMetadata::CERTIFICATE)
                ->assertJsonPath('changes.0', 'Дни недели в календаре')
                ->assertJsonPath('changes.1', str_repeat('я', 160))
                ->assertJsonPath('changes.2', '<img src=x onerror=1>')
                ->assertJsonMissingPath('changes.3')
                ->assertJsonMissingPath('publisher_git_commit');
            $this->assertMatchesRegularExpression('/^[a-f0-9]{128,180}$/', $this->getJson('/install/android.json')->json('signature'));
            $this->get('/install')
                ->assertOk()
                ->assertSee('Дни недели в календаре')
                ->assertSee('Версия 1.1.12')
                ->assertSee('<img src=x onerror=1>')
                ->assertSee('Скачать для Android')
                ->assertDontSee('SHA-256', false)
                ->assertDontSee($sha, false);
            $manifest = json_decode((string) file_get_contents($dir.'/android-latest.json'), true);
            $manifest['signature'] = str_repeat('ab', 70);
            file_put_contents($dir.'/android-latest.json', json_encode($manifest));
            $this->getJson('/install/android.json')->assertNotFound();
        } finally {
            @unlink($path);
            @unlink($dir.'/android-latest.json');
        }
    }

    private function signedManifest(array $fields): array
    {
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        $this->assertNotFalse($key);
        $details = openssl_pkey_get_details($key);
        $this->assertIsArray($details);
        $der = base64_decode((string) preg_replace('/-----[^-]+-----|\s/', '', (string) $details['key']), true);
        $this->assertNotFalse($der);
        config(['install.metadata_public_key' => bin2hex($der)]);
        $canonical = AndroidReleaseMetadata::canonical($fields);
        $this->assertNotNull($canonical);
        $this->assertTrue(openssl_sign($canonical, $signature, $key, OPENSSL_ALGO_SHA256));
        $fields['signature'] = bin2hex($signature);

        return $fields;
    }
}
