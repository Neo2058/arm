<?php

namespace Tests\Unit\Chat;

use App\Services\Chat\ChatCrypto;
use Illuminate\Contracts\Encryption\DecryptException;
use RuntimeException;
use Tests\TestCase;

class ChatCryptoTest extends TestCase
{
    private const KEY_A = 'base64:AQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQE=';

    private const KEY_B = 'base64:AgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgI=';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'chat.key' => self::KEY_A,
            'chat.previous_keys' => [],
            'chat.cipher' => 'aes-256-gcm',
        ]);
    }

    public function test_encrypt_decrypt_roundtrip(): void
    {
        $crypto = app(ChatCrypto::class);
        $envelope = $crypto->encrypt('На смену');

        $this->assertSame('На смену', $crypto->decrypt($envelope));
    }

    public function test_envelope_is_v1_kid_payload(): void
    {
        $binary = str_repeat("\x01", 32);
        $kid = substr(hash('sha256', $binary), 0, 8);
        $envelope = app(ChatCrypto::class)->encrypt('hello');

        $this->assertMatchesRegularExpression('/^v1\.[0-9a-f]{8}\./', $envelope);
        $this->assertStringStartsWith('v1.'.$kid.'.', $envelope);
        $this->assertNotSame('hello', $envelope);
        $this->assertStringNotContainsString('hello', $envelope);
    }

    public function test_empty_key_fails_closed_on_encrypt_and_decrypt(): void
    {
        config(['chat.key' => '']);
        $crypto = app(ChatCrypto::class);

        try {
            $crypto->encrypt('x');
            $this->fail('encrypt must fail closed on an empty key');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('key', strtolower($e->getMessage()));
        }

        try {
            $crypto->decrypt('v1.deadbeef.payload');
            $this->fail('decrypt must fail closed on an empty key');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('key', strtolower($e->getMessage()));
        }
    }

    public function test_short_key_fails_closed_on_encrypt_and_decrypt(): void
    {
        config(['chat.key' => 'base64:short']);
        $crypto = app(ChatCrypto::class);

        $this->expectException(RuntimeException::class);
        $crypto->encrypt('x');
    }

    public function test_short_key_fails_closed_on_decrypt(): void
    {
        config(['chat.key' => 'base64:QQ==']);
        $crypto = app(ChatCrypto::class);

        $this->expectException(RuntimeException::class);
        $crypto->decrypt('v1.deadbeef.payload');
    }

    public function test_rotation_decrypts_with_previous_keys(): void
    {
        config(['chat.key' => self::KEY_A, 'chat.previous_keys' => []]);
        $envelope = app(ChatCrypto::class)->encrypt('rotate-me');

        config(['chat.key' => self::KEY_B, 'chat.previous_keys' => [self::KEY_A]]);
        $this->assertSame('rotate-me', app(ChatCrypto::class)->decrypt($envelope));
    }

    public function test_unknown_kid_tries_current_then_previous_keys(): void
    {
        config(['chat.key' => self::KEY_A, 'chat.previous_keys' => []]);
        $envelope = app(ChatCrypto::class)->encrypt('legacy');
        $tampered = preg_replace('/^v1\.[0-9a-f]{8}\./', 'v1.ffffffff.', $envelope, 1);
        $this->assertNotSame($envelope, $tampered);

        config(['chat.key' => self::KEY_B, 'chat.previous_keys' => [self::KEY_A]]);
        $this->assertSame('legacy', app(ChatCrypto::class)->decrypt($tampered));
    }

    public function test_garbage_envelope_throws(): void
    {
        $this->expectException(DecryptException::class);
        app(ChatCrypto::class)->decrypt('not-an-envelope');
    }
}
