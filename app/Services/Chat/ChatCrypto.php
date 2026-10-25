<?php

namespace App\Services\Chat;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use RuntimeException;

class ChatCrypto
{
    public function encrypt(string $plaintext): string
    {
        $key = $this->currentKey();
        $payload = (new Encrypter($key, $this->cipher()))->encrypt($plaintext, serialize: false);

        return 'v1.'.$this->kid($key).'.'.$payload;
    }

    public function decrypt(string $envelope): string
    {
        $this->currentKey();

        if (! str_starts_with($envelope, 'v1.')) {
            throw new DecryptException('Invalid chat ciphertext.');
        }

        $rest = substr($envelope, 3);
        $dot = strpos($rest, '.');
        if ($dot === false || $dot === 0 || $dot === strlen($rest) - 1) {
            throw new DecryptException('Invalid chat ciphertext.');
        }

        $kid = substr($rest, 0, $dot);
        $payload = substr($rest, $dot + 1);
        $known = $this->keyForKid($kid);
        if ($known !== null) {
            return (new Encrypter($known, $this->cipher()))->decrypt($payload, unserialize: false);
        }

        foreach ($this->allKeys() as $key) {
            try {
                return (new Encrypter($key, $this->cipher()))->decrypt($payload, unserialize: false);
            } catch (DecryptException) {
                continue;
            }
        }

        throw new DecryptException('Could not decrypt the chat payload.');
    }

    private function currentKey(): string
    {
        return $this->parseKey(config('chat.key'));
    }

    /**
     * @return list<string>
     */
    private function allKeys(): array
    {
        $keys = [$this->currentKey()];
        foreach (config('chat.previous_keys', []) as $raw) {
            try {
                $keys[] = $this->parseKey($raw);
            } catch (RuntimeException) {
                continue;
            }
        }

        return $keys;
    }

    private function keyForKid(string $kid): ?string
    {
        foreach ($this->allKeys() as $key) {
            if (hash_equals($this->kid($key), $kid)) {
                return $key;
            }
        }

        return null;
    }

    private function kid(string $binaryKey): string
    {
        return substr(hash('sha256', $binaryKey), 0, 8);
    }

    private function cipher(): string
    {
        return strtolower((string) config('chat.cipher', 'aes-256-gcm'));
    }

    private function parseKey(mixed $value): string
    {
        if (! is_string($value)) {
            throw new RuntimeException('Chat encryption key is missing.');
        }

        $value = trim($value);
        if ($value === '') {
            throw new RuntimeException('Chat encryption key is missing.');
        }

        if (str_starts_with($value, 'base64:')) {
            $decoded = base64_decode(substr($value, 7), true);
            if ($decoded === false) {
                throw new RuntimeException('Chat encryption key is invalid.');
            }
            $value = $decoded;
        }

        if (! Encrypter::supported($value, $this->cipher())) {
            throw new RuntimeException('Chat encryption key is invalid.');
        }

        return $value;
    }
}
