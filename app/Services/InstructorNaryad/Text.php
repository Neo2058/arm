<?php

namespace App\Services\InstructorNaryad;

final class Text
{
    public static function toUtf8Auto(string $bytes): string
    {
        $bytes = self::stripBomAndSub($bytes);
        if ($bytes === '') {
            return '';
        }
        if (mb_check_encoding($bytes, 'UTF-8')) {
            return $bytes;
        }
        $converted = @iconv('CP866', 'UTF-8//IGNORE', $bytes);

        return $converted === false ? $bytes : $converted;
    }

    public static function stripBomAndSub(string $bytes): string
    {
        if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
            $bytes = substr($bytes, 3);
        }

        return rtrim($bytes, "\x1A\x00");
    }

    public static function trim(string $s): string
    {
        return trim($s, " \t\n\r\0\x0B");
    }

    public static function collapseSpaces(string $s): string
    {
        $s = preg_replace('/[ \t\n\r]+/u', ' ', self::trim($s)) ?? '';

        return self::trim($s);
    }

    public static function upper(string $s): string
    {
        return mb_strtoupper($s, 'UTF-8');
    }

    /** @return list<string> */
    public static function splitLines(string $utf8): array
    {
        $utf8 = self::stripBomAndSub($utf8);
        $utf8 = str_replace(["\r\n", "\r"], "\n", $utf8);
        if ($utf8 === '') {
            return [];
        }

        return explode("\n", $utf8);
    }

    public static function slice(string $line, int $from, int $to): string
    {
        $len = mb_strlen($line, 'UTF-8');
        if ($from >= $len) {
            return '';
        }
        $to = min($to, $len);
        if ($to <= $from) {
            return '';
        }

        return mb_substr($line, $from, $to - $from, 'UTF-8');
    }

    public static function charAt(string $s, int $i): string
    {
        return mb_substr($s, $i, 1, 'UTF-8');
    }

    public static function isDigit(string $c): bool
    {
        return $c !== '' && $c >= '0' && $c <= '9';
    }

    public static function isCyrLetter(string $c): bool
    {
        return (bool) preg_match('/^\p{Cyrillic}$/u', $c);
    }
}
