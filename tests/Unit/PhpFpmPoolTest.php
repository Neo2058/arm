<?php

namespace Tests\Unit;

use Tests\TestCase;

class PhpFpmPoolTest extends TestCase
{
    public function test_www_conf_raises_max_children_for_chat_photos(): void
    {
        $conf = file_get_contents(base_path('docker/php-fpm/www.conf'));

        $this->assertNotFalse($conf);
        $this->assertMatchesRegularExpression('/^\[www\]/m', $conf);
        $this->assertDoesNotMatchRegularExpression('/^\s*listen\s*=/m', $conf);
        $this->assertDoesNotMatchRegularExpression('/^\s*user\s*=/m', $conf);
        $this->assertDoesNotMatchRegularExpression('/^\s*pm\s*=/m', $conf);
        $this->assertSame(1, preg_match('/^\s*pm\.max_children\s*=\s*(\d+)\s*$/m', $conf, $matches));
        $this->assertGreaterThanOrEqual(15, (int) $matches[1]);
    }

    public function test_dockerfile_and_compose_install_www_conf_as_drop_in(): void
    {
        $dockerfile = file_get_contents(base_path('Dockerfile'));
        $mount = './docker/php-fpm/www.conf:/usr/local/etc/php-fpm.d/zz-www.conf:ro';
        $prod = file_get_contents(base_path('docker-compose.prod.yml'));
        $local = file_get_contents(base_path('docker-compose.yaml'));

        $this->assertNotFalse($dockerfile);
        $this->assertNotFalse($prod);
        $this->assertNotFalse($local);
        $this->assertStringContainsString(
            'COPY docker/php-fpm/www.conf /usr/local/etc/php-fpm.d/zz-www.conf',
            $dockerfile
        );
        $this->assertStringContainsString($mount, $prod);
        $this->assertStringContainsString($mount, $local);
    }
}
