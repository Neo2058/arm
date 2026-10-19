<?php

namespace Tests\Unit;

use App\Services\ClickHouseService;
use Tests\TestCase;

class ClickHouseServiceTest extends TestCase
{
    public function test_payload_without_user_is_system_event(): void
    {
        $row = ClickHouseService::payload('telegram_start_command', 0, ['chat_id' => 42]);

        $this->assertSame(0, $row['user_id']);
        $this->assertSame(ClickHouseService::SYSTEM_ROLE, $row['user_role']);
        $this->assertSame('', $row['user_column']);
        $this->assertSame('telegram_start_command', $row['action_type']);
        $this->assertSame(0, $row['resource_id']);
        $this->assertStringContainsString('"chat_id":42', $row['details']);
    }

    public function test_fake_records_payload_without_dispatch(): void
    {
        ClickHouseService::fake();
        ClickHouseService::log('login', 7, ['via' => 'web']);

        $recorded = ClickHouseService::recorded();
        $this->assertCount(1, $recorded);
        $this->assertSame('login', $recorded[0]['action_type']);
        $this->assertSame(7, $recorded[0]['resource_id']);
        $this->assertStringContainsString('"via":"web"', $recorded[0]['details']);
    }

    public function test_fake_select_does_not_query_clickhouse(): void
    {
        ClickHouseService::fake();

        $this->assertSame([], ClickHouseService::select('SELECT 1'));
    }

    public function test_quote_escapes_quotes_and_backslashes(): void
    {
        $this->assertSame("'15'", ClickHouseService::quote('15'));
        $this->assertSame("'O\\'Hara'", ClickHouseService::quote("O'Hara"));
        $this->assertSame("'a\\\\b'", ClickHouseService::quote('a\\b'));
    }
}
