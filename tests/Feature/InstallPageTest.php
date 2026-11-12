<?php

namespace Tests\Feature;

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
        file_put_contents($path, 'apk-bytes');

        try {
            $this->get('/install/android')
                ->assertOk()
                ->assertHeader('content-type', 'application/vnd.android.package-archive');
            $this->getJson('/install/android.json')->assertNotFound();
            file_put_contents($dir.'/android-latest.json', json_encode([
                'package' => 'ru.tch15.arm',
                'version' => '1.1.6',
                'version_code' => 22,
                'sha256' => 'abc',
                'size' => 1,
                'url' => '/install/android',
                'publisher_git_commit' => 'should-not-leak',
            ]));
            $this->getJson('/install/android.json')
                ->assertOk()
                ->assertHeader('Cache-Control', 'no-store, private')
                ->assertJsonPath('package', 'ru.tch15.arm')
                ->assertJsonPath('version_code', 22)
                ->assertJsonPath('size', strlen('apk-bytes'))
                ->assertJsonMissingPath('publisher_git_commit');
        } finally {
            @unlink($path);
            @unlink($dir.'/android-latest.json');
        }
    }
}
