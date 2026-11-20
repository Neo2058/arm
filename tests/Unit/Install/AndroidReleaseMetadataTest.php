<?php

namespace Tests\Unit\Install;

use App\Services\Install\AndroidReleaseMetadata;
use Tests\TestCase;

class AndroidReleaseMetadataTest extends TestCase
{
    private const GOLDEN = '{"certificate_sha256":"2d0cdbf6e20385dfab263e4b09cc0729fbbb6fa80074866913df8bd8c9e3c99f","changes":["Дни недели в календаре"],"package":"ru.tch15.arm","sha256":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","size":9,"url":"/install/android","version":"1.1.12","version_code":28}';

    public function test_canonical_payload_is_compact_and_ordered(): void
    {
        $this->assertSame(self::GOLDEN, AndroidReleaseMetadata::canonical([
            'package' => 'ru.tch15.arm',
            'version' => '1.1.12',
            'version_code' => 28,
            'sha256' => str_repeat('a', 64),
            'size' => 9,
            'url' => '/install/android',
            'certificate_sha256' => AndroidReleaseMetadata::CERTIFICATE,
            'changes' => ['Дни недели в календаре'],
        ]));
    }

    public function test_signature_roundtrip_and_tamper_is_rejected(): void
    {
        [$key, $publicHex] = $this->ephemeralKey();
        $message = AndroidReleaseMetadata::canonical([
            'package' => 'ru.tch15.arm',
            'version' => '1.1.12',
            'version_code' => 28,
            'sha256' => str_repeat('a', 64),
            'size' => 9,
            'url' => '/install/android',
            'certificate_sha256' => AndroidReleaseMetadata::CERTIFICATE,
            'changes' => ['Дни недели в календаре'],
        ]);
        $this->assertNotNull($message);
        $this->assertTrue(openssl_sign($message, $signature, $key, OPENSSL_ALGO_SHA256));
        $hex = bin2hex($signature);
        $this->assertTrue(AndroidReleaseMetadata::verify($message, $hex, $publicHex));
        $this->assertFalse(AndroidReleaseMetadata::verify($message.'x', $hex, $publicHex));
        $this->assertFalse(AndroidReleaseMetadata::verify($message, $hex, str_repeat('ab', 91)));
        $this->assertFalse(AndroidReleaseMetadata::verify($message, '', $publicHex));
    }

    public function test_foreign_package_or_certificate_is_rejected(): void
    {
        $this->assertNull(AndroidReleaseMetadata::canonical([
            'package' => 'ru.tch15.arm.monitor',
            'version' => '1.1.12',
            'version_code' => 28,
            'sha256' => str_repeat('a', 64),
            'size' => 9,
            'url' => '/install/android',
            'certificate_sha256' => AndroidReleaseMetadata::CERTIFICATE,
            'changes' => ['Дни недели в календаре'],
        ]));
        $this->assertNull(AndroidReleaseMetadata::canonical([
            'package' => 'ru.tch15.arm',
            'version' => '1.1.12',
            'version_code' => 28,
            'sha256' => str_repeat('a', 64),
            'size' => 9,
            'url' => '/install/android',
            'certificate_sha256' => str_repeat('ab', 32),
            'changes' => ['Дни недели в календаре'],
        ]));
    }

    public function test_pinned_public_key_is_present(): void
    {
        $this->assertSame(182, strlen(AndroidReleaseMetadata::publicKey()));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{182}$/', AndroidReleaseMetadata::publicKey());
    }

    /**
     * @return array{0: \OpenSSLAsymmetricKey, 1: string}
     */
    private function ephemeralKey(): array
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

        return [$key, bin2hex($der)];
    }
}
