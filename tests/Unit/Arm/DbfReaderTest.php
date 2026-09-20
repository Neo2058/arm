<?php

namespace Tests\Unit\Arm;

use App\Services\Arm\DbfReader;
use Tests\Support\DbfWriter;
use Tests\TestCase;

class DbfReaderTest extends TestCase
{
    public function test_reads_cp866_numeric_and_date_fields(): void
    {
        $path = sys_get_temp_dir().'/arm_graf_'.uniqid().'.dbf';
        DbfWriter::write($path, [
            ['name' => 'KODEL', 'type' => 'C', 'length' => 2],
            ['name' => 'NAZEL', 'type' => 'C', 'length' => 15],
            ['name' => 'CHAS', 'type' => 'N', 'length' => 5, 'decimal' => 2],
            ['name' => 'DAT', 'type' => 'D', 'length' => 8],
        ], [
            ['KODEL' => '01', 'NAZEL' => 'Рабочий', 'CHAS' => '7.50', 'DAT' => '20260115'],
            ['KODEL' => '02', 'NAZEL' => 'Суббота', 'CHAS' => '   ', 'DAT' => '00000000'],
        ]);

        $rows = (new DbfReader($path))->all();
        @unlink($path);

        $this->assertCount(2, $rows);
        $this->assertSame('01', $rows[0]['KODEL']);
        $this->assertSame('Рабочий', $rows[0]['NAZEL']);
        $this->assertSame(7.5, $rows[0]['CHAS']);
        $this->assertSame('2026-01-15', $rows[0]['DAT']);
        $this->assertSame(0.0, $rows[1]['CHAS']);
        $this->assertNull($rows[1]['DAT']);
    }
}
