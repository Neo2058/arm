<?php

namespace Tests\Support;

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

        $fh = fopen($path, 'wb');
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
                $value = $row[$field['name']] ?? '';
                $record .= match ($field['type']) {
                    'N' => str_pad((string) $value, $field['length'], ' ', STR_PAD_LEFT),
                    'D' => str_pad(str_replace('-', '', (string) $value), 8),
                    'L' => $value ? 'T' : 'F',
                    default => self::padCp866((string) $value, $field['length']),
                };
            }
            fwrite($fh, $record);
        }
        fwrite($fh, "\x1A");
        fclose($fh);
    }

    private static function padCp866(string $value, int $length): string
    {
        $encoded = iconv('UTF-8', 'CP866//IGNORE', $value);
        if ($encoded === false) {
            $encoded = $value;
        }

        return str_pad(substr($encoded, 0, $length), $length);
    }
}
