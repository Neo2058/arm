<?php

namespace Tests\Unit;

use Tests\TestCase;

class Php2shellMitigationTest extends TestCase
{
    public function test_dockerfile_pins_patched_php_8_2_35(): void
    {
        $dockerfile = file_get_contents(base_path('Dockerfile'));

        $this->assertNotFalse($dockerfile);
        $this->assertMatchesRegularExpression('/^FROM php:8\.2\.35-fpm\s*$/m', $dockerfile);
        $this->assertStringContainsString('docker/php/zz-php2shell.ini', $dockerfile);
    }

    public function test_php2shell_ini_disables_upload_progress_before_parse(): void
    {
        $ini = file_get_contents(base_path('docker/php/zz-php2shell.ini'));

        $this->assertNotFalse($ini);
        $this->assertMatchesRegularExpression('/^\s*session\.upload_progress\.enabled\s*=\s*Off\s*$/mi', $ini);
        $this->assertMatchesRegularExpression('/^\s*session\.use_strict_mode\s*=\s*1\s*$/mi', $ini);
        $this->assertMatchesRegularExpression('/^\s*expose_php\s*=\s*Off\s*$/mi', $ini);
    }

    public function test_prod_compose_bind_mounts_php2shell_ini(): void
    {
        $yml = file_get_contents(base_path('docker-compose.prod.yml'));

        $this->assertNotFalse($yml);
        $this->assertStringContainsString(
            './docker/php/zz-php2shell.ini:/usr/local/etc/php/conf.d/zz-php2shell.ini:ro',
            $yml
        );
    }
}
