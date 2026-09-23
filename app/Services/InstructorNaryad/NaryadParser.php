<?php

namespace App\Services\InstructorNaryad;

final class NaryadParser
{
    private const LEFT_END = 13;

    private const NAME_END = 49;

    /**
     * @return array{meta: array, assignments: list<array>}
     */
    public static function parse(string $utf8): array
    {
        $lines = Text::splitLines($utf8);
        $meta = self::parseMeta($lines);
        $assignments = [];
        $section = '';
        $lastSostav = '';
        $routeNumber = 0;
        $shiftCode = '';

        foreach ($lines as $idx => $raw) {
            $lineNumber = $idx + 1;
            if (self::isJunk($raw)) {
                continue;
            }

            if (self::isListArea($raw)) {
                if (self::isRuler($raw)) {
                    continue;
                }
                $people = FioParser::extract($raw);
                if ($people !== []) {
                    self::emit($assignments, $people, $section, '', null, $lineNumber, $routeNumber, $shiftCode);
                } else {
                    $title = Text::collapseSpaces($raw);
                    if ($title !== '') {
                        $section = $title;
                        $lastSostav = '';
                        $routeNumber = 0;
                        $shiftCode = self::shiftCodeFromSection($title, 0);
                    }
                }
                continue;
            }

            $leftRaw = Text::slice($raw, 0, self::LEFT_END);
            $nameRaw = Text::slice($raw, self::LEFT_END, self::NAME_END);
            $timeRaw = Text::slice($raw, self::NAME_END, 90);
            $duty = self::parseDutyTime($timeRaw);
            $left = Text::collapseSpaces($leftRaw);

            if (self::isRouteStart($leftRaw)) {
                $digits = Text::slice($leftRaw, 0, 2);
                $routeNumber = (int) $digits;
                $section = 'маршрут '.$digits;
                $shiftCode = self::shiftCodeFromSection($section, $routeNumber);
                $rest = Text::collapseSpaces(Text::slice($leftRaw, 2, 13));
                if ($rest !== '') {
                    $lastSostav = $rest;
                }
            } elseif (self::isSostavToken($left)) {
                $lastSostav = $left;
            } elseif ($left !== '' && $duty !== null) {
                $section = $left;
                $lastSostav = '';
                $routeNumber = 0;
                $shiftCode = self::shiftCodeFromSection($left, 0);
            } elseif ($left !== '' && $duty === null && FioParser::extract($raw) !== []) {
                $section = $left;
                $lastSostav = '';
                $routeNumber = 0;
                $shiftCode = self::shiftCodeFromSection($left, 0);
            }

            if ($duty !== null) {
                self::emit($assignments, FioParser::extract($nameRaw), $section, $lastSostav, $duty, $lineNumber, $routeNumber, $shiftCode);
                continue;
            }

            if (self::isSostavToken($left)) {
                continue;
            }

            $people = FioParser::extract($nameRaw);
            if ($people !== []) {
                self::emit($assignments, $people, $section, $lastSostav, null, $lineNumber, $routeNumber, $shiftCode);
            }
        }

        return ['meta' => $meta, 'assignments' => $assignments];
    }

    /**
     * @param  list<string>  $lines
     * @return array{date_text: string, weekday: string, day: int, month: int, year: int, weekend: bool, even_day: bool, ok: bool}
     */
    public static function parseMeta(array $lines): array
    {
        $meta = [
            'date_text' => '',
            'weekday' => '',
            'day' => 0,
            'month' => 0,
            'year' => 0,
            'weekend' => false,
            'even_day' => false,
            'ok' => false,
        ];
        $marker = 'НАРЯД ЛОКОМОТИВНЫХ БРИГАД НА ';
        foreach ($lines as $raw) {
            $t = Text::collapseSpaces($raw);
            $pos = mb_strpos($t, $marker, 0, 'UTF-8');
            if ($pos === false) {
                continue;
            }
            $rest = Text::trim(mb_substr($t, $pos + mb_strlen($marker, 'UTF-8'), null, 'UTF-8'));
            if (mb_strlen($rest, 'UTF-8') < 10) {
                continue;
            }
            if (! preg_match('/^(\d{2})\.(\d{2})\.(\d{4})/', $rest, $m)) {
                continue;
            }
            $meta['day'] = (int) $m[1];
            $meta['month'] = (int) $m[2];
            $meta['year'] = (int) $m[3];
            $meta['date_text'] = $m[0];
            $wd = Text::trim(mb_substr($rest, 10, null, 'UTF-8'));
            if ($wd !== '' && $wd[0] === ',') {
                $wd = Text::trim(substr($wd, 1));
            }
            $wd = preg_replace('/[,\s].*$/u', '', $wd) ?? $wd;
            $meta['weekday'] = Text::upper($wd);
            $meta['weekend'] = in_array($meta['weekday'], ['СУББОТА', 'ВОСКРЕСЕНЬЕ'], true);
            $meta['even_day'] = ($meta['day'] % 2) === 0;
            $meta['ok'] = $meta['day'] > 0 && $meta['year'] > 0;
            break;
        }

        return $meta;
    }

    public static function shiftCodeFromSection(string $section, int $routeNumber): string
    {
        if ($routeNumber > 0) {
            return (string) $routeNumber;
        }
        $s = Text::upper(Text::collapseSpaces($section));
        if ($s === '') {
            return '';
        }
        if (str_starts_with($s, 'МЗ-') && preg_match('/^МЗ-(\d+)/u', $s, $m)) {
            return 'М-'.$m[1];
        }
        if (str_starts_with($s, 'М-') && preg_match('/^М-(\d+)/u', $s, $m)) {
            return 'М-'.$m[1];
        }
        if (str_starts_with($s, 'ПОДМЕНА') && preg_match('/(\d+)/', $s, $m)) {
            return 'П-'.$m[1];
        }
        if (mb_strpos($s, 'ДДЭ', 0, 'UTF-8') !== false) {
            return 'РЭ';
        }
        if (str_starts_with($s, 'РЕЗ ДЕПО')) {
            return 'РЭ';
        }
        if (mb_strpos($s, 'БРАТ', 0, 'UTF-8') !== false && mb_strpos($s, 'ПОМ', 0, 'UTF-8') === false) {
            return 'РБ';
        }

        return '';
    }

    private static function isJunk(string $raw): bool
    {
        $t = Text::trim($raw);
        if ($t === '' || self::isRuler($raw)) {
            return true;
        }
        if (mb_strpos($t, 'НАРЯД ЛОКОМОТИВНЫХ', 0, 'UTF-8') !== false) {
            return true;
        }
        if (str_starts_with($t, 'Мар.')) {
            return true;
        }
        foreach (['НАРЯДЧИК', 'Заместитель', 'начальника депо'] as $needle) {
            if (mb_strpos($t, $needle, 0, 'UTF-8') !== false) {
                return true;
            }
        }

        return false;
    }

    private static function isRuler(string $line): bool
    {
        $bars = 0;
        $other = 0;
        $len = mb_strlen($line, 'UTF-8');
        for ($i = 0; $i < $len; $i++) {
            $c = Text::charAt($line, $i);
            if ($c === ' ' || $c === "\t") {
                continue;
            }
            if (in_array($c, ['─', '-', '—', '='], true)) {
                $bars++;
            } else {
                $other++;
            }
        }

        return $bars >= 8 && $other === 0;
    }

    private static function isListArea(string $line): bool
    {
        return mb_strlen($line, 'UTF-8') >= 3
            && Text::charAt($line, 0) === ' '
            && Text::charAt($line, 1) === ' '
            && Text::charAt($line, 2) !== ' ';
    }

    private static function isRouteStart(string $leftRaw): bool
    {
        if (mb_strlen($leftRaw, 'UTF-8') < 2) {
            return false;
        }
        if (! Text::isDigit(Text::charAt($leftRaw, 0)) || ! Text::isDigit(Text::charAt($leftRaw, 1))) {
            return false;
        }

        return mb_strlen($leftRaw, 'UTF-8') === 2 || Text::charAt($leftRaw, 2) === ' ';
    }

    private static function isSostavToken(string $s): bool
    {
        if ($s === '' || ! Text::isDigit(Text::charAt($s, 0))) {
            return false;
        }
        $len = mb_strlen($s, 'UTF-8');
        for ($i = 0; $i < $len; $i++) {
            $c = Text::charAt($s, $i);
            if (! Text::isDigit($c) && $c !== '-' && $c !== ' ') {
                return false;
            }
        }

        return $len >= 3;
    }

    /**
     * @return array{range: string, start_clock: string, start_station: string, end_clock: string, end_station: string}|null
     */
    private static function parseDutyTime(string $tail): ?array
    {
        if (! preg_match(
            '/(\d{1,2}\.\d{2})\s*(\p{Cyrillic}*)\s*-\s*(\d{1,2}\.\d{2})\s*(\p{Cyrillic}*)/u',
            $tail,
            $m
        )) {
            return null;
        }
        $s1 = Text::upper($m[2]);
        $s2 = Text::upper($m[4]);
        $offset = strpos($tail, $m[0]);
        $flags = Text::collapseSpaces(substr($tail, ($offset === false ? 0 : $offset) + strlen($m[0])));
        $range = $m[1].($s1 !== '' ? ' '.$s1 : '').' — '.$m[3].($s2 !== '' ? ' '.$s2 : '');
        if ($flags !== '') {
            $range .= ' '.$flags;
        }

        return [
            'range' => $range,
            'start_clock' => $m[1],
            'start_station' => $s1,
            'end_clock' => $m[3],
            'end_station' => $s2,
        ];
    }

    /**
     * @param  list<array>  $assignments
     * @param  list<array>  $people
     * @param  array<string, string>|null  $duty
     */
    private static function emit(
        array &$assignments,
        array $people,
        string $section,
        string $sostav,
        ?array $duty,
        int $lineNumber,
        int $routeNumber,
        string $shiftCode
    ): void {
        $timeRange = $duty['range'] ?? '';
        if ($section === '' && $timeRange === '') {
            return;
        }
        foreach ($people as $p) {
            $assignments[] = [
                'section' => $section,
                'sostav' => $sostav,
                'fio_display' => $p['display'],
                'fio_key' => $p['key'],
                'surname' => $p['surname'],
                'time_range' => $timeRange,
                'marks' => $p['marks'],
                'line_number' => $lineNumber,
                'route_number' => $routeNumber,
                'shift_code' => $shiftCode,
                'start_clock' => $duty['start_clock'] ?? '',
                'start_station' => $duty['start_station'] ?? '',
                'end_clock' => $duty['end_clock'] ?? '',
                'end_station' => $duty['end_station'] ?? '',
            ];
        }
    }
}
