<?php

namespace App\Services\Install;

class AndroidReleaseMetadata
{
    public const PACKAGE = 'ru.tch15.arm';

    public const CERTIFICATE = '2d0cdbf6e20385dfab263e4b09cc0729fbbb6fa80074866913df8bd8c9e3c99f';

    public const URL = '/install/android';

    public static function publicKey(): string
    {
        return strtolower(trim((string) config('install.metadata_public_key', '')));
    }

    public static function fields(array $raw): ?array
    {
        $package = (string) ($raw['package'] ?? '');
        $url = (string) ($raw['url'] ?? '');
        $version = (string) ($raw['version'] ?? '');
        $code = (int) ($raw['version_code'] ?? 0);
        $size = (int) ($raw['size'] ?? 0);
        $sha = self::sha256($raw['sha256'] ?? '');
        $cert = self::sha256($raw['certificate_sha256'] ?? '');
        $changes = self::changes($raw['changes'] ?? []);
        if ($package !== self::PACKAGE || $url !== self::URL || $version === '' || $code < 1 || $size < 1 || $sha === '' || $cert !== self::CERTIFICATE || $changes === []) {
            return null;
        }

        return [
            'certificate_sha256' => $cert,
            'changes' => $changes,
            'package' => $package,
            'sha256' => $sha,
            'size' => $size,
            'url' => $url,
            'version' => $version,
            'version_code' => $code,
        ];
    }

    public static function canonical(array $raw): ?string
    {
        $fields = self::fields($raw);
        if ($fields === null) {
            return null;
        }
        $json = json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($json) ? $json : null;
    }

    public static function verify(string $message, string $signatureHex, ?string $publicKeyHex = null): bool
    {
        if ($message === '') {
            return false;
        }
        $signature = self::binary(strtolower(trim($signatureHex)));
        $der = self::binary(strtolower(trim($publicKeyHex ?? self::publicKey())));
        if ($signature === null || $der === null || strlen($der) < 50) {
            return false;
        }
        $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END PUBLIC KEY-----\n";
        $key = @openssl_pkey_get_public($pem);
        if ($key === false) {
            return false;
        }
        $result = @openssl_verify($message, $signature, $key, OPENSSL_ALGO_SHA256);

        return $result === 1;
    }

    public static function changes(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $item) {
            if (! is_string($item)) {
                continue;
            }
            $text = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $item) ?? '');
            $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
            if ($text === '') {
                continue;
            }
            $out[] = mb_substr($text, 0, 160);
            if (count($out) >= 8) {
                break;
            }
        }

        return $out;
    }

    public static function sha256(mixed $value): string
    {
        $text = strtolower(trim((string) $value));

        return preg_match('/^[a-f0-9]{64}$/D', $text) ? $text : '';
    }

    public static function signature(mixed $value): string
    {
        $text = strtolower(trim((string) $value));

        return preg_match('/^[a-f0-9]{128,180}$/D', $text) ? $text : '';
    }

    private static function binary(string $hex): ?string
    {
        if ($hex === '' || strlen($hex) % 2 !== 0 || ! ctype_xdigit($hex)) {
            return null;
        }
        $value = hex2bin($hex);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
