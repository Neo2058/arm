<?php

namespace App\Services\InstructorNaryad;

final class ShiftCatalog
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function loadTable(string $utf8): array
    {
        $out = [];
        $cur = self::emptyEntry();
        $open = false;
        $flush = function () use (&$out, &$cur, &$open): void {
            if ($open && $cur['code'] !== '' && $cur['start_clock'] !== '') {
                if ($cur['route'] === 0 && ctype_digit($cur['code'])) {
                    $cur['route'] = (int) $cur['code'];
                }
                $out[] = $cur;
            }
            $cur = self::emptyEntry();
            $open = false;
        };

        foreach (Text::splitLines($utf8) as $raw) {
            $line = Text::trim($raw);
            if ($line === '' || str_starts_with($line, 'KIND ') || str_starts_with($line, 'TITLE ')) {
                continue;
            }
            if ($line === 'SHIFT') {
                $flush();
                $open = true;
                continue;
            }
            if (! $open) {
                continue;
            }
            $after = static fn (string $key): string => Text::trim(substr($line, strlen($key)));
            if (str_starts_with($line, 'ROUTE ')) {
                $cur['route'] = (int) $after('ROUTE ');
                if ($cur['code'] === '' && $cur['route'] > 0) {
                    $cur['code'] = (string) $cur['route'];
                }
            } elseif (str_starts_with($line, 'CODE ')) {
                $cur['code'] = $after('CODE ');
            } elseif (str_starts_with($line, 'ID ')) {
                $cur['id'] = $after('ID ');
            } elseif (str_starts_with($line, 'PARITY ')) {
                $p = $after('PARITY ');
                $cur['parity'] = $p === 'even' || $p === 'odd' ? $p : 'both';
            } elseif (str_starts_with($line, 'START ')) {
                [$cur['start_clock'], $cur['start_place']] = self::splitPlaceClock($after('START '));
            } elseif (str_starts_with($line, 'END ')) {
                [$cur['end_clock'], $cur['end_place']] = self::splitPlaceClock($after('END '));
            } elseif (str_starts_with($line, 'LINE ')) {
                $cur['line'] = $after('LINE ');
            } elseif (str_starts_with($line, 'DURATION ')) {
                $cur['duration'] = $after('DURATION ');
            } elseif (str_starts_with($line, 'WORK ')) {
                $cur['work'][] = $after('WORK ');
            } elseif (str_starts_with($line, 'REST ')) {
                $cur['rest'][] = $after('REST ');
            }
        }
        $flush();

        return $out;
    }

    public static function detectKind(string $utf8): ?string
    {
        foreach (Text::splitLines($utf8) as $raw) {
            $line = Text::trim($raw);
            if (str_starts_with($line, 'KIND ')) {
                $k = Text::trim(substr($line, 5));

                return in_array($k, ['work', 'weekend'], true) ? $k : null;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $assignment
     * @param  list<array<string, mixed>>  $work
     * @param  list<array<string, mixed>>  $weekend
     * @return array<string, mixed>|null
     */
    public static function findShift(array $meta, array $assignment, array $work, array $weekend): ?array
    {
        $code = $assignment['shift_code'] ?? '';
        $start = $assignment['start_clock'] ?? '';
        if ($code === '' || $start === '') {
            return null;
        }
        $table = ($meta['weekend'] ?? false) ? $weekend : $work;
        $even = self::railwayEvenDay($meta, $start, $assignment['end_clock'] ?? '');
        $wantPlace = self::normalizeStation($assignment['start_station'] ?? '');
        $best = null;
        $bestScore = -1;
        foreach ($table as $e) {
            if (($e['code'] ?? '') !== $code) {
                continue;
            }
            $parity = $e['parity'] ?? 'both';
            if ($parity === 'even' && ($meta['ok'] ?? false) && ! $even) {
                continue;
            }
            if ($parity === 'odd' && ($meta['ok'] ?? false) && $even) {
                continue;
            }
            if (! self::clocksEqual($e['start_clock'] ?? '', $start)) {
                continue;
            }
            $score = 100;
            $score += $parity === 'both' ? 5 : 15;
            if ($wantPlace !== '' && self::normalizeStation($e['start_place'] ?? '') === $wantPlace) {
                $score += 40;
            }
            if (($assignment['end_clock'] ?? '') !== '' && self::clocksEqual($e['end_clock'] ?? '', $assignment['end_clock'])) {
                $score += 20;
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $e;
            }
        }

        return $best;
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $entry
     */
    public static function formatDecode(array $entry, array $meta, bool $railwayEven): string
    {
        $code = $entry['code'] ?? '';
        $route = (int) ($entry['route'] ?? 0);
        $lines = [];
        $head = '';
        if ($code !== '' && ($route === 0 || $code !== (string) $route)) {
            $head .= $code.' · ';
        }
        $head .= 'смена '.($entry['id'] ?? '');
        $head .= ' · '.(($meta['weekend'] ?? false) ? 'выходные' : 'рабочие');
        $parity = $entry['parity'] ?? 'both';
        if ($parity === 'even') {
            $head .= ' · чётный';
        } elseif ($parity === 'odd') {
            $head .= ' · нечётный';
        } elseif ($meta['ok'] ?? false) {
            $head .= ' · '.($railwayEven ? 'чётный' : 'нечётный');
        }
        $dur = self::stripDurationParity($entry['duration'] ?? '');
        if ($dur !== '') {
            $head .= ' · часы '.$dur;
        }
        if (($entry['line'] ?? '') !== '') {
            $head .= ' · '.$entry['line'];
        }
        $lines[] = $head;
        foreach ($entry['work'] ?? [] as $w) {
            $lines[] = $w;
        }
        $rest = $entry['rest'] ?? [];
        if ($rest !== []) {
            $lines[] = 'отстой/отдых: '.implode('; ', $rest);
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function railwayEvenDay(array $meta, string $startClock, string $endClock = ''): bool
    {
        $calendarEven = ($meta['ok'] ?? false) ? (((int) $meta['day']) % 2 === 0) : (bool) ($meta['even_day'] ?? false);
        $haveStart = self::parseClockParts($startClock, $sh, $sm);
        $haveEnd = self::parseClockParts($endClock, $eh, $em);
        $next = false;
        if ($haveStart) {
            $next = self::isNextSutki($sh, $sm);
        }
        if ($haveEnd) {
            $s = $sh * 60 + $sm;
            $e = $eh * 60 + $em;
            if ($haveStart && $e < $s) {
                $next = true;
            } elseif (self::isNextSutki($eh, $em)) {
                $next = true;
            }
        }

        return $next ? ! $calendarEven : $calendarEven;
    }

    public static function durationToMinutes(string $duration): int
    {
        $s = self::stripDurationParity(Text::trim($duration));
        if ($s === '') {
            return 0;
        }
        $dot = strpos($s, '.');
        try {
            if ($dot === false) {
                return ((int) $s) * 60;
            }
            $hours = (int) substr($s, 0, $dot);
            $minutes = (int) substr($s, $dot + 1);
            if ($hours < 0 || $minutes < 0 || $minutes > 59) {
                return 0;
            }

            return $hours * 60 + $minutes;
        } catch (\Throwable) {
            return 0;
        }
    }

    public static function normalizeStation(string $place): string
    {
        $p = Text::upper(Text::trim($place));
        if ($p === '') {
            return '';
        }
        if (in_array($p, ['Д', 'ДОК', 'ДПЧ'], true)) {
            return 'ДПЧ';
        }
        if (in_array($p, ['ЛБЛ', 'ЛБ'], true)) {
            return 'ЛБ';
        }
        if (in_array($p, ['ФЗЛ', 'ФЗТ'], true)) {
            return 'ФЗТ';
        }

        return $p;
    }

    public static function clocksEqual(string $a, string $b): bool
    {
        if (! self::parseClockParts($a, $h1, $m1) || ! self::parseClockParts($b, $h2, $m2)) {
            return Text::trim($a) === Text::trim($b);
        }

        return $h1 === $h2 && $m1 === $m2;
    }

    public static function stripDurationParity(string $d): string
    {
        foreach (['-нчт', '-чт'] as $suf) {
            if (str_ends_with($d, $suf)) {
                return substr($d, 0, -strlen($suf));
            }
        }

        return $d;
    }

    /** @return array{0: string, 1: string} */
    private static function splitPlaceClock(string $rest): array
    {
        $t = Text::collapseSpaces($rest);
        $sp = strpos($t, ' ');
        if ($sp === false) {
            return [$t, ''];
        }

        return [substr($t, 0, $sp), substr($t, $sp + 1)];
    }

    private static function parseClockParts(string $clock, ?int &$h, ?int &$m): bool
    {
        $h = 0;
        $m = 0;
        $s = Text::trim($clock);
        $dot = strpos($s, '.');
        if ($dot === false || $dot === 0) {
            return false;
        }
        $h = (int) substr($s, 0, $dot);
        $m = (int) substr($s, $dot + 1);

        return $h >= 0 && $h <= 47 && $m >= 0 && $m <= 59;
    }

    private static function isNextSutki(int $h, int $m): bool
    {
        $minutes = $h * 60 + $m;

        return $minutes >= 22 * 60 || $minutes < 4 * 60;
    }

    /** @return array<string, mixed> */
    private static function emptyEntry(): array
    {
        return [
            'route' => 0,
            'code' => '',
            'id' => '',
            'parity' => 'both',
            'start_clock' => '',
            'start_place' => '',
            'end_clock' => '',
            'end_place' => '',
            'line' => '',
            'duration' => '',
            'work' => [],
            'rest' => [],
        ];
    }
}
