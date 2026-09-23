<?php

namespace App\Services\InstructorNaryad;

final class SearchEngine
{
    /**
     * @param  list<array{label: string, meta: array, assignments: list<array>}>  $naryads
     * @param  list<string>  $queryLines
     * @param  list<array<string, mixed>>  $workShifts
     * @param  list<array<string, mixed>>  $weekendShifts
     * @return array{text: string, hits: list<array>, missing: list<string>}
     */
    public static function run(array $naryads, array $queryLines, array $workShifts, array $weekendShifts): array
    {
        $queries = [];
        $seen = [];
        foreach ($queryLines as $line) {
            $t = Text::trim($line);
            if ($t === '') {
                continue;
            }
            $q = FioParser::parseQuery($t);
            if ($q['ok'] && $q['hasInitials']) {
                $id = $q['key'];
            } elseif ($q['ok']) {
                $id = 'S:'.$q['surname'];
            } else {
                $id = 'R:'.$t;
            }
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $queries[] = $t;
        }

        $hits = [];
        $missing = [];
        foreach ($queries as $raw) {
            $q = FioParser::parseQuery($raw);
            $label = FioParser::label($q);
            $found = false;
            if ($q['ok']) {
                foreach ($naryads as $file) {
                    foreach ($file['assignments'] as $a) {
                        if (! FioParser::matches($q, $a)) {
                            continue;
                        }
                        $hit = [
                            'source_file' => $file['label'],
                            'query' => $label,
                            'section' => $a['section'],
                            'sostav' => $a['sostav'],
                            'fio' => $a['fio_display'],
                            'time_range' => $a['time_range'],
                            'marks' => $a['marks'],
                            'naryad_date' => $file['meta']['date_text'] ?? '',
                            'naryad_weekday' => $file['meta']['weekday'] ?? '',
                            'naryad_month' => (int) ($file['meta']['month'] ?? 0),
                            'line_number' => (int) $a['line_number'],
                            'shift_decode' => '',
                            'shift_missing' => false,
                            'duration_minutes' => 0,
                        ];
                        if (($a['shift_code'] ?? '') !== '' && ($workShifts !== [] || $weekendShifts !== [])) {
                            $shift = ShiftCatalog::findShift($file['meta'], $a, $workShifts, $weekendShifts);
                            if ($shift) {
                                $even = ShiftCatalog::railwayEvenDay(
                                    $file['meta'],
                                    $a['start_clock'] ?? '',
                                    $a['end_clock'] ?? ''
                                );
                                $hit['shift_decode'] = ShiftCatalog::formatDecode($shift, $file['meta'], $even);
                                $hit['duration_minutes'] = ShiftCatalog::durationToMinutes($shift['duration'] ?? '');
                            } else {
                                $hit['shift_missing'] = true;
                            }
                        }
                        $hits[] = $hit;
                        $found = true;
                    }
                }
            }
            if (! $found) {
                $missing[] = $label;
            }
        }

        return [
            'text' => self::formatResult($queries, $hits),
            'hits' => $hits,
            'missing' => $missing,
        ];
    }

    /**
     * @param  list<string>  $queries
     * @param  list<array<string, mixed>>  $hits
     */
    public static function formatResult(array $queries, array $hits): string
    {
        $byQuery = [];
        foreach ($hits as $h) {
            $byQuery[$h['query']][] = $h;
        }
        $out = "Результаты поиска\n=================\n\n";
        foreach ($queries as $raw) {
            $q = FioParser::parseQuery($raw);
            $label = FioParser::label($q);
            $list = $byQuery[$label] ?? [];
            $out .= 'Запрос: '.$label;
            if ($list !== []) {
                $out .= self::querySummary($list);
            }
            $out .= "\n";
            if ($list === []) {
                $out .= "  не найден\n\n";
                continue;
            }
            $out .= '  найдено: '.count($list)."\n\n";
            foreach ($list as $h) {
                $out .= self::appendHit($h)."\n";
            }
        }

        return $out;
    }

    /** @param  list<array<string, mixed>>  $hits */
    public static function querySummary(array $hits): string
    {
        $byMonth = [];
        foreach ($hits as $h) {
            $month = (int) ($h['naryad_month'] ?? 0);
            if ($month < 1 && strlen($h['naryad_date'] ?? '') >= 5 && ($h['naryad_date'][2] ?? '') === '.') {
                $month = (int) substr($h['naryad_date'], 3, 2);
            }
            $mins = (int) ($h['duration_minutes'] ?? 0);
            if ($month >= 1 && $mins > 0) {
                $byMonth[$month] = ($byMonth[$month] ?? 0) + $mins;
            }
        }
        ksort($byMonth);
        $parts = '';
        if ($byMonth !== []) {
            $bits = [];
            foreach ($byMonth as $m => $mins) {
                $bits[] = sprintf('%02d - %d.%02d', $m, intdiv($mins, 60), $mins % 60);
            }
            $parts .= ' Часов за '.implode(', ', $bits);
        }
        $days = [];
        $unnamed = 0;
        foreach ($hits as $h) {
            if (mb_stripos($h['section'] ?? '', 'выходной') === false) {
                continue;
            }
            $d = $h['naryad_date'] ?? '';
            if ($d !== '') {
                $days[$d] = true;
            } else {
                $unnamed++;
            }
        }
        $n = count($days) + $unnamed;
        if ($n > 0) {
            $parts .= ' Выходных - '.$n;
        }

        return $parts;
    }

    /** @param  array<string, mixed>  $h */
    private static function appendHit(array $h): string
    {
        $out = '  • '.$h['source_file'];
        if (($h['naryad_date'] ?? '') !== '') {
            $out .= ', '.$h['naryad_date'];
            if (($h['naryad_weekday'] ?? '') !== '') {
                $out .= ', '.$h['naryad_weekday'];
            }
        }
        if ((int) ($h['line_number'] ?? 0) > 0) {
            $out .= ', стр. '.$h['line_number'];
        }
        $out .= "\n    ".$h['fio'];
        if (($h['marks'] ?? '') !== '') {
            $out .= ' ('.$h['marks'].')';
        }
        $out .= "\n";
        if (($h['section'] ?? '') !== '' || ($h['sostav'] ?? '') !== '') {
            $out .= '    '.$h['section'];
            if (($h['sostav'] ?? '') !== '') {
                if (($h['section'] ?? '') !== '') {
                    $out .= ', ';
                }
                $out .= 'состав '.$h['sostav'];
            }
            $out .= "\n";
        }
        if (($h['time_range'] ?? '') !== '') {
            $out .= '    '.$h['time_range']."\n";
        }
        if (($h['shift_decode'] ?? '') !== '') {
            foreach (preg_split("/\n/", trim($h['shift_decode'])) as $line) {
                if ($line !== '') {
                    $out .= '    '.$line."\n";
                }
            }
        } elseif ($h['shift_missing'] ?? false) {
            $out .= '    расшифровка смены не найдена в графике';
            $wd = $h['naryad_weekday'] ?? '';
            $out .= in_array($wd, ['СУББОТА', 'ВОСКРЕСЕНЬЕ'], true) ? ' выходных' : ' рабочих';
            $out .= " дней\n";
        }

        return $out;
    }
}
