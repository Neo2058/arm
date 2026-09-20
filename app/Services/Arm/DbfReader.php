<?php

namespace App\Services\Arm;

use Generator;
use InvalidArgumentException;
use RuntimeException;

/**
 * Чтение FoxPro 2 / dBASE III DBF (кодировка CP866).
 */
class DbfReader
{
    public function __construct(
        private string $path,
        private string $encoding = 'CP866',
    ) {
        if (! is_file($this->path)) {
            throw new InvalidArgumentException('DBF не найден: '.$this->path);
        }
    }

    /**
     * @return list<array{name: string, type: string, length: int, decimal: int}>
     */
    public function fields(): array
    {
        $handle = $this->open();
        try {
            return $this->readFieldDescriptors($handle);
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    public function records(): Generator
    {
        $handle = $this->open();
        try {
            $header = fread($handle, 32);
            if ($header === false || strlen($header) < 32) {
                throw new RuntimeException('Повреждён заголовок DBF: '.$this->path);
            }

            $recordCount = unpack('V', substr($header, 4, 4))[1];
            $headerLength = unpack('v', substr($header, 8, 2))[1];
            $recordLength = unpack('v', substr($header, 10, 2))[1];
            $fields = $this->readFieldDescriptors($handle, $headerLength);

            fseek($handle, $headerLength);

            for ($i = 0; $i < $recordCount; $i++) {
                $raw = fread($handle, $recordLength);
                if ($raw === false || strlen($raw) < $recordLength) {
                    break;
                }
                if ($raw[0] === '*') {
                    continue;
                }

                yield $this->decodeRecord($raw, $fields);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return iterator_to_array($this->records(), false);
    }

    /**
     * @return resource
     */
    private function open()
    {
        $handle = fopen($this->path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Не удалось открыть '.$this->path);
        }

        return $handle;
    }

    /**
     * @return list<array{name: string, type: string, length: int, decimal: int}>
     */
    private function readFieldDescriptors($handle, ?int $headerLength = null): array
    {
        if ($headerLength === null) {
            $header = fread($handle, 32);
            if ($header === false || strlen($header) < 32) {
                throw new RuntimeException('Повреждён заголовок DBF: '.$this->path);
            }
            $headerLength = unpack('v', substr($header, 8, 2))[1];
        } else {
            fseek($handle, 32);
        }

        $fields = [];
        $consumed = 32;
        while ($consumed + 32 <= $headerLength) {
            $raw = fread($handle, 32);
            if ($raw === false || $raw === '' || ord($raw[0]) === 0x0D) {
                break;
            }
            $consumed += 32;
            $name = rtrim(substr($raw, 0, 11), "\x00 ");
            $fields[] = [
                'name' => strtoupper($name),
                'type' => $raw[11],
                'length' => ord($raw[16]),
                'decimal' => ord($raw[17]),
            ];
        }

        return $fields;
    }

    /**
     * @param  list<array{name: string, type: string, length: int, decimal: int}>  $fields
     * @return array<string, mixed>
     */
    private function decodeRecord(string $raw, array $fields): array
    {
        $offset = 1;
        $row = [];
        foreach ($fields as $field) {
            $chunk = substr($raw, $offset, $field['length']);
            $offset += $field['length'];
            $row[$field['name']] = $this->decodeValue($chunk, $field['type']);
        }

        return $row;
    }

    private function decodeValue(string $chunk, string $type): mixed
    {
        return match ($type) {
            'N', 'F' => $this->decodeNumber($chunk),
            'D' => $this->decodeDate($chunk),
            'L' => in_array(strtoupper(trim($chunk)), ['T', 'Y', '1'], true),
            default => $this->decodeString($chunk),
        };
    }

    private function decodeNumber(string $chunk): float
    {
        $s = trim($chunk);
        if ($s === '' || $s === '.') {
            return 0.0;
        }

        return (float) $s;
    }

    private function decodeDate(string $chunk): ?string
    {
        $s = trim($chunk);
        if ($s === '' || ! preg_match('/^\d{8}$/', $s) || $s === '00000000') {
            return null;
        }

        $year = substr($s, 0, 4);
        $month = substr($s, 4, 2);
        $day = substr($s, 6, 2);
        if (! checkdate((int) $month, (int) $day, (int) $year)) {
            return null;
        }

        return $year.'-'.$month.'-'.$day;
    }

    private function decodeString(string $chunk): string
    {
        $converted = @iconv($this->encoding, 'UTF-8//IGNORE', $chunk);
        if ($converted === false) {
            $converted = $chunk;
        }

        return trim($converted);
    }
}
