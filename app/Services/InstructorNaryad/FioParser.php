<?php

namespace App\Services\InstructorNaryad;

final class FioParser
{
    /**
     * @return list<array{surname: string, display: string, key: string, marks: string}>
     */
    public static function extract(string $text): array
    {
        $found = [];
        if (! preg_match_all(
            '/([А-ЯЁа-яё][А-ЯЁа-яё\-]{1,})\s+([А-ЯЁа-яё])\.\s*([А-ЯЁа-яё])\.?/u',
            $text,
            $matches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE
        )) {
            return [];
        }

        foreach ($matches as $m) {
            $surname = Text::upper($m[1][0]);
            $i1 = Text::upper($m[2][0]);
            $i2 = Text::upper($m[3][0]);
            $endByte = $m[0][1] + strlen($m[0][0]);
            $tail = substr($text, $endByte);
            $marks = self::takeMarks($tail);
            $found[] = [
                'surname' => $surname,
                'display' => $surname.' '.$i1.'.'.$i2,
                'key' => $surname.'|'.$i1.'|'.$i2,
                'marks' => $marks,
            ];
        }

        return $found;
    }

    /**
     * @return array{ok: bool, hasInitials: bool, display: string, key: string, surname: string, original: string}
     */
    public static function parseQuery(string $line): array
    {
        $original = Text::trim($line);
        $empty = [
            'ok' => false,
            'hasInitials' => false,
            'display' => '',
            'key' => '',
            'surname' => '',
            'original' => $original,
        ];
        if ($original === '') {
            return $empty;
        }

        $fios = self::extract(Text::upper($original));
        if ($fios !== []) {
            $f = $fios[0];

            return [
                'ok' => true,
                'hasInitials' => true,
                'display' => $f['display'],
                'key' => $f['key'],
                'surname' => $f['surname'],
                'original' => $original,
            ];
        }

        $upper = Text::upper($original);
        $surname = preg_replace('/[^\p{Cyrillic}\-]/u', '', $upper) ?? '';
        if (mb_strlen($surname, 'UTF-8') >= 2) {
            return [
                'ok' => true,
                'hasInitials' => false,
                'display' => $surname,
                'key' => '',
                'surname' => $surname,
                'original' => $original,
            ];
        }

        return $empty;
    }

    public static function matches(array $query, array $assignment): bool
    {
        if (! ($query['ok'] ?? false)) {
            return false;
        }
        if ($query['hasInitials']) {
            return ($query['key'] ?? '') === ($assignment['fio_key'] ?? '');
        }

        return ($query['surname'] ?? '') !== '' && $query['surname'] === ($assignment['surname'] ?? '');
    }

    public static function label(array $query): string
    {
        return $query['display'] !== '' ? $query['display'] : $query['original'];
    }

    private static function takeMarks(string $tail): string
    {
        $marks = [];
        $i = 0;
        $len = mb_strlen($tail, 'UTF-8');
        while ($i < $len) {
            while ($i < $len && preg_match('/\s/u', Text::charAt($tail, $i))) {
                $i++;
            }
            if ($i >= $len) {
                break;
            }
            $c = Text::charAt($tail, $i);
            if (in_array($c, ['-', '.', '!', '*', '+'], true)) {
                $chunk = '';
                while ($i < $len) {
                    $c = Text::charAt($tail, $i);
                    if (! in_array($c, ['-', '.', '!', '*', '+'], true)) {
                        break;
                    }
                    if (in_array($c, ['!', '*', '+'], true)) {
                        $chunk .= $c;
                    }
                    $i++;
                }
                if ($chunk !== '') {
                    $marks[] = $chunk;
                }
                continue;
            }
            $rest = mb_strtolower(mb_substr($tail, $i, 4, 'UTF-8'), 'UTF-8');
            if (str_starts_with($rest, 'отп')) {
                $marks[] = 'отп.';
                $i += 3;
                if ($i < $len && Text::charAt($tail, $i) === '.') {
                    $i++;
                }
                continue;
            }
            break;
        }

        return Text::collapseSpaces(implode(' ', $marks));
    }
}
