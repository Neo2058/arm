<?php

namespace Tests\Unit\Arm;

use App\Services\Arm\FoxTime;
use Carbon\Carbon;
use Tests\TestCase;

class FoxTimeTest extends TestCase
{
    public function test_decimal_is_hours_and_minutes_not_fraction(): void
    {
        $this->assertSame(5 * 60 + 30, FoxTime::toMinutes(5.30));
        $this->assertSame(13 * 60 + 10, FoxTime::toMinutes(13.10));
        $this->assertSame(22 * 60, FoxTime::toMinutes(22.00));
    }

    public function test_format_matches_foxpro_str_hh_mm(): void
    {
        $this->assertSame(' 5.30', FoxTime::format(5.30));
        $this->assertSame('13.10', FoxTime::format(13.10));
        $this->assertSame(' 0.00', FoxTime::format(0));
    }

    public function test_overnight_end_moves_to_next_day(): void
    {
        $day = Carbon::parse('2026-03-10');
        $end = FoxTime::endAt($day, 22.00, 6.30);
        $this->assertSame('2026-03-11 06:30:00', $end->format('Y-m-d H:i:s'));
        $startNext = FoxTime::at(Carbon::parse('2026-03-11'), 6.30);
        $this->assertSame(0.0, FoxTime::hoursBetween($end, $startNext));
    }
}
