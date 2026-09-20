<?php

namespace Tests\Feature;

use Tests\TestCase;

class InstallPageTest extends TestCase
{
    public function test_install_page_renders(): void
    {
        $this->get('/install')
            ->assertOk()
            ->assertSee('ТЧ-15 на телефон')
            ->assertSee('Android')
            ->assertSee('iPhone');
    }

    public function test_missing_binaries_return_404(): void
    {
        $this->get('/install/android')->assertNotFound();
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
        } finally {
            @unlink($path);
        }
    }
}
