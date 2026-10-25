<?php

namespace Tests\Unit;

use Tests\TestCase;

class PhpFpmPoolTest extends TestCase
{
    public function test_www_conf_raises_max_children_for_chat_photos(): void
    {
        $conf = file_get_contents(base_path('docker/php-fpm/www.conf'));

        $this->assertNotFalse($conf);
        $this->assertSame(1, preg_match('/^\s*pm\.max_children\s*=\s*(\d+)\s*$/m', $conf, $matches));
        $this->assertGreaterThanOrEqual(15, (int) $matches[1]);
    }

    public function test_dockerfile_and_prod_compose_install_www_conf_as_drop_in(): void
    {
        $dockerfile = file_get_contents(base_path('Dockerfile'));
        $yml = file_get_contents(base_path('docker-compose.prod.yml'));

        $this->assertNotFalse($dockerfile);
        $this->assertNotFalse($yml);
        $this->assertStringContainsString(
            'COPY docker/php-fpm/www.conf /usr/local/etc/php-fpm.d/zz-www.conf',
            $dockerfile
        );
        $this->assertStringContainsString(
            './docker/php-fpm/www.conf:/usr/local/etc/php-fpm.d/zz-www.conf:ro',
            $yml
        );
    }
}
