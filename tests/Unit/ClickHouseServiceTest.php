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
}
