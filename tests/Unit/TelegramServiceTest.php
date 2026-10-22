<?php

namespace Tests\Unit;

use App\Services\TelegramService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramServiceTest extends TestCase
{
    public function test_disabled_when_token_empty(): void
    {
        config(['services.telegram.bot_token' => '']);
        $this->assertFalse(TelegramService::enabled());

        Http::fake();
        TelegramService::send('hello');
        Http::assertNothingSent();
    }

    public function test_enabled_when_token_present(): void
    {
        config(['services.telegram.bot_token' => 'test-token']);
        $this->assertTrue(TelegramService::enabled());
    }
}
