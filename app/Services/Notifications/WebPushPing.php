<?php

namespace App\Services\Notifications;

use App\Models\PushSubscription;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WebPushPing
{
    /**
     * @param  list<int>  $userIds
     */
    public function pingUsers(array $userIds): void
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        if ($userIds === [] || ! $this->configured()) {
            return;
        }

        $rows = PushSubscription::query()
            ->whereIn('user_id', $userIds)
            ->where('channel', 'web')
            ->get();

        foreach ($rows as $row) {
            $this->ping($row);
        }
    }

    public function configured(): bool
    {
        $public = trim((string) config('notifications.vapid.public_key'));
        $private = trim((string) config('notifications.vapid.private_key'));

        return $public !== '' && $private !== '';
    }

    public function ping(PushSubscription $subscription): void
    {
        $endpoint = trim((string) $subscription->endpoint);
        if ($endpoint === '' || ! str_starts_with($endpoint, 'https://')) {
            $subscription->delete();

            return;
        }

        try {
            $jwt = $this->jwt($endpoint);
            $public = trim((string) config('notifications.vapid.public_key'));
            $response = Http::timeout(8)
                ->withHeaders([
                    'TTL' => '120',
                    'Urgency' => 'high',
                    'Authorization' => 'vapid t='.$jwt.', k='.$public,
                    'Crypto-Key' => 'p256ecdsa='.$public,
                ])
                ->withBody('', 'application/octet-stream')
                ->post($endpoint);
        } catch (\Throwable $e) {
            Log::warning('Web push ping failed', ['id' => $subscription->id]);

            return;
        }

        if (in_array($response->status(), [404, 410], true)) {
            $subscription->delete();
        }
    }

    public function jwt(string $endpoint): string
    {
        $audience = $this->audience($endpoint);
        $header = $this->b64(json_encode(['typ' => 'JWT', 'alg' => 'ES256'], JSON_THROW_ON_ERROR));
        $payload = $this->b64(json_encode([
            'aud' => $audience,
            'exp' => time() + 12 * 3600,
            'sub' => $this->subject(),
        ], JSON_THROW_ON_ERROR));
        $unsigned = $header.'.'.$payload;
        $key = $this->privateKey();
        $der = '';
        if (! openssl_sign($unsigned, $der, $key, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Не удалось подписать VAPID.');
        }

        return $unsigned.'.'.$this->b64($this->derToJose($der));
    }

    public function publicKeyBytes(): string
    {
        return $this->b64decode(trim((string) config('notifications.vapid.public_key')));
    }

    private function audience(string $endpoint): string
    {
        $parts = parse_url($endpoint);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        if ($host === '') {
            throw new \RuntimeException('Пустой push endpoint.');
        }
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return $scheme.'://'.$host.$port;
    }

    private function subject(): string
    {
        $subject = trim((string) config('notifications.vapid.subject', 'mailto:noreply@localhost'));
        if ($subject === '' || (! str_starts_with($subject, 'mailto:') && ! str_starts_with($subject, 'https://'))) {
            return 'mailto:noreply@localhost';
        }

        return $subject;
    }

    private function privateKey(): \OpenSSLAsymmetricKey
    {
        $raw = trim((string) config('notifications.vapid.private_key'));
        $pem = str_contains($raw, 'BEGIN') ? str_replace('\\n', "\n", $raw) : $this->pemFromScalar($this->b64decode($raw));
        $key = openssl_pkey_get_private($pem);
        if ($key === false) {
            throw new \RuntimeException('Некорректный VAPID_PRIVATE_KEY.');
        }

        return $key;
    }

    private function pemFromScalar(string $d): string
    {
        if (strlen($d) !== 32) {
            throw new \RuntimeException('Некорректный VAPID_PRIVATE_KEY.');
        }

        $details = openssl_pkey_get_details($this->publicOnlyKey());
        $x = $details['ec']['x'] ?? '';
        $y = $details['ec']['y'] ?? '';
        $point = "\x04".$x.$y;

        $ec = "\x02\x01\x01"."\x04\x20".$d."\xa0\x0a"."\x06\x08"."\x2a\x86\x48\xce\x3d\x03\x01\x07"."\xa1\x44"."\x03\x42\x00".$point;
        $der = "\x30".chr(strlen($ec)).$ec;

        return "-----BEGIN EC PRIVATE KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END EC PRIVATE KEY-----\n";
    }

    private function publicOnlyKey(): \OpenSSLAsymmetricKey
    {
        $point = $this->publicKeyBytes();
        if (strlen($point) !== 65 || $point[0] !== "\x04") {
            throw new \RuntimeException('Некорректный VAPID_PUBLIC_KEY.');
        }
        $x = substr($point, 1, 32);
        $y = substr($point, 33, 32);
        $bit = "\x00".$point;
        $alg = "\x30\x13"."\x06\x07"."\x2a\x86\x48\xce\x3d\x02\x01"."\x06\x08"."\x2a\x86\x48\xce\x3d\x03\x01\x07";
        $spki = "\x30\x59".$alg."\x03\x42".$bit;
        $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($spki), 64, "\n")."-----END PUBLIC KEY-----\n";
        $key = openssl_pkey_get_public($pem);
        if ($key === false) {
            throw new \RuntimeException('Некорректный VAPID_PUBLIC_KEY.');
        }

        return $key;
    }

    private function derToJose(string $der): string
    {
        $offset = 0;
        if (! isset($der[$offset]) || ord($der[$offset]) !== 0x30) {
            throw new \RuntimeException('Некорректная подпись VAPID.');
        }
        $offset++;
        $len = ord($der[$offset] ?? "\x00");
        $offset++;
        if ($len & 0x80) {
            $offset += $len & 0x7F;
        }
        [$r, $offset] = $this->derInt($der, $offset);
        [$s] = $this->derInt($der, $offset);
        $r = str_pad(ltrim($r, "\x00"), 32, "\x00", STR_PAD_LEFT);
        $s = str_pad(ltrim($s, "\x00"), 32, "\x00", STR_PAD_LEFT);
        if (strlen($r) !== 32 || strlen($s) !== 32) {
            throw new \RuntimeException('Некорректная подпись VAPID.');
        }

        return $r.$s;
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function derInt(string $der, int $offset): array
    {
        if (! isset($der[$offset]) || ord($der[$offset]) !== 0x02) {
            throw new \RuntimeException('Некорректная подпись VAPID.');
        }
        $offset++;
        $len = ord($der[$offset] ?? "\x00");
        $offset++;
        $value = substr($der, $offset, $len);
        if (strlen($value) !== $len) {
            throw new \RuntimeException('Некорректная подпись VAPID.');
        }

        return [$value, $offset + $len];
    }

    private function b64(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function b64decode(string $value): string
    {
        $value = strtr($value, '-_', '+/');
        $pad = strlen($value) % 4;
        if ($pad > 0) {
            $value .= str_repeat('=', 4 - $pad);
        }
        $decoded = base64_decode($value, true);
        if (! is_string($decoded)) {
            throw new \RuntimeException('Некорректный ключ VAPID.');
        }

        return $decoded;
    }
}
