<?php

namespace Tests\Unit\InstructorNaryad;

use App\Services\InstructorNaryad\FioParser;
use App\Services\InstructorNaryad\NaryadParser;
use App\Services\InstructorNaryad\SearchEngine;
use App\Services\InstructorNaryad\ShiftCatalog;
use Tests\TestCase;

class NaryadSearchEngineTest extends TestCase
{
    private function sampleNaryad(): string
    {
        $pad = static fn (string $s): string => $s.str_repeat(' ', max(0, 90 - mb_strlen($s, 'UTF-8')));

        return implode("\n", [
            $pad('                 НАРЯД ЛОКОМОТИВНЫХ БРИГАД НА 02.09.2026, СРЕДА'),
            $pad(''),
            $pad('Мар. Состав   Фамилия                            Время Пункт'),
            $pad('────────────────────────────────────────────────────────────────────────────────'),
            $pad('01 2505- 16  РОГАЧ А.П                             5.14ДПЧ   - 9.01 БР'),
            $pad('24 27007-9   ВИКИН А.Б                             5.10ЗБЛ   - 8.27 БР'),
            $pad('   27008     ЛОГИНОВ Р.А.                          8.10БР    -16.25 ДПЧ'),
            $pad('             БОБРОВ В.А                           16.12ДПЧ   - 0.27 МР'),
            $pad('Мз-1 маневры БАНГЕРТ А.Я                           5.31ЗБЛ   - 8.27 ЗБЛ'),
            $pad('Подмена 1    ДЕЕВ М.С.                            10.03БР    -18.33 ВЛЖ'),
            $pad('резерв ДДЭ   ГРИГОРЬЕВ П.Н. !                      5.00ДПЧ   - 9.00 ДПЧ'),
            '  выходной      МШ',
            '  ────────────────',
            '  КУРЧАТОВ К.А.-',
        ]);
    }

    public function test_parses_header_and_named_shifts(): void
    {
        $doc = NaryadParser::parse($this->sampleNaryad());
        $this->assertTrue($doc['meta']['ok']);
        $this->assertSame('02.09.2026', $doc['meta']['date_text']);
        $this->assertSame('СРЕДА', $doc['meta']['weekday']);
        $this->assertFalse($doc['meta']['weekend']);
        $this->assertTrue($doc['meta']['even_day']);

        $byKey = [];
        foreach ($doc['assignments'] as $a) {
            $byKey[$a['fio_key']] = $a;
        }
        $this->assertArrayHasKey('БОБРОВ|В|А', $byKey);
        $this->assertSame('24', $byKey['БОБРОВ|В|А']['shift_code']);
        $this->assertSame('16.12', $byKey['БОБРОВ|В|А']['start_clock']);
        $this->assertSame('М-1', $byKey['БАНГЕРТ|А|Я']['shift_code']);
        $this->assertSame('П-1', $byKey['ДЕЕВ|М|С']['shift_code']);
        $this->assertSame('РЭ', $byKey['ГРИГОРЬЕВ|П|Н']['shift_code']);
        $this->assertStringContainsString('выходной', $byKey['КУРЧАТОВ|К|А']['section']);
    }

    public function test_search_hours_and_days_off(): void
    {
        $doc = NaryadParser::parse($this->sampleNaryad());
        $catalog = ShiftCatalog::loadTable(<<<TXT
SHIFT
CODE 24
ID 3+
PARITY both
START 16.12 д
END 0.27 мр
DURATION 8.15
WORK приёмка в отстое
TXT);
        $result = SearchEngine::run(
            [['label' => '2.txt', 'meta' => $doc['meta'], 'assignments' => $doc['assignments']]],
            ['БОБРОВ В.А', 'КУРЧАТОВ К.А'],
            $catalog,
            []
        );
        $this->assertStringContainsString('Часов за 09 - 8.15', $result['text']);
        $this->assertStringContainsString('Выходных - 1', $result['text']);
        $this->assertStringContainsString('приёмка в отстое', $result['text']);
        $this->assertStringContainsString('маршрут 24', $result['text']);
    }

    public function test_overnight_parity_on_odd_date(): void
    {
        $meta = ['ok' => true, 'day' => 5, 'even_day' => false, 'weekend' => true];
        $this->assertTrue(ShiftCatalog::railwayEvenDay($meta, '18.18', '1.57'));
        $this->assertFalse(ShiftCatalog::railwayEvenDay($meta, '8.38', '16.30'));
        $this->assertSame(8 * 60 + 15, ShiftCatalog::durationToMinutes('8.15'));
        $this->assertSame(7 * 60 + 29, ShiftCatalog::durationToMinutes('7.29-чт'));
    }

    public function test_fio_query_normalizes_dot(): void
    {
        $q = FioParser::parseQuery('БОБРОВ В.А.');
        $this->assertTrue($q['ok']);
        $this->assertSame('БОБРОВ|В|А', $q['key']);
    }
}
