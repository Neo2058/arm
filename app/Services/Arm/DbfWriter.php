<?php

namespace App\Services\Arm;

use RuntimeException;

/**
 * Запись dBASE III DBF в CP866 (как LSBUH.DBF Печатников, version 0x03).
 */
class DbfWriter
{
    /**
     * @param  list<array{name: string, type: string, length: int, decimal?: int}>  $fields
     * @param  list<array<string, mixed>>  $rows
     */
    public static function write(string $path, array $fields, array $rows): void
    {
        $recordLength = 1;
        foreach ($fields as $field) {
            $recordLength += $field['length'];
        }
        $headerLength = 32 + (count($fields) * 32) + 1;
        $now = getdate();
        $dir = dirname($path);
        if ($dir !== '' && $dir !== '.' && ! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $fh = fopen($path, 'wb');
        if ($fh === false) {
            throw new RuntimeException('Не удалось записать DBF: '.$path);
        }
        fwrite($fh, pack('C4', 0x03, $now['year'] % 100, $now['mon'], $now['mday']));
        fwrite($fh, pack('V', count($rows)));
        fwrite($fh, pack('v', $headerLength));
        fwrite($fh, pack('v', $recordLength));
        fwrite($fh, str_repeat("\x00", 20));

        foreach ($fields as $field) {
            $name = str_pad(substr(strtoupper($field['name']), 0, 11), 11, "\x00");
            $desc = $name.$field['type'].pack('V', 0).chr($field['length']).chr($field['decimal'] ?? 0).str_repeat("\x00", 14);
            fwrite($fh, $desc);
        }
        fwrite($fh, "\x0D");

        foreach ($rows as $row) {
            $record = ' ';
            foreach ($fields as $field) {
                $value = $row[$field['name']] ?? $row[strtolower($field['name'])] ?? '';
                $record .= match ($field['type']) {
                    'N', 'F' => self::formatNumber($value, $field['length'], (int) ($field['decimal'] ?? 0)),
                    'D' => str_pad(str_replace('-', '', (string) $value), 8),
                    'L' => self::formatLogical($value),
                    default => self::padCp866((string) $value, $field['length']),
                };
            }
            fwrite($fh, $record);
        }
        fwrite($fh, "\x1A");
        fclose($fh);
    }

    private static function formatNumber(mixed $value, int $length, int $decimal): string
    {
        if ($value === null || $value === '') {
            return str_repeat(' ', $length);
        }
        if (is_string($value)) {
            $value = trim($value);
            if ($value === '' || $value === '.') {
                return str_repeat(' ', $length);
            }
        }
        $formatted = $decimal > 0
            ? number_format((float) $value, $decimal, '.', '')
            : (string) (int) round((float) $value);
        if (strlen($formatted) > $length) {
            $formatted = substr($formatted, 0, $length);
        }

        return str_pad($formatted, $length, ' ', STR_PAD_LEFT);
    }

    private static function formatLogical(mixed $value): string
    {
        if ($value === true || $value === 1 || $value === '1'
            || (is_string($value) && in_array(strtoupper(trim($value)), ['T', 'Y', 'Д'], true))) {
            return 'T';
        }

        return 'F';
    }

    private static function padCp866(string $value, int $length): string
    {
        $encoded = @iconv('UTF-8', 'CP866//IGNORE', $value);
        if ($encoded === false) {
            $encoded = $value;
        }

        return str_pad(substr($encoded, 0, $length), $length);
    }
}
