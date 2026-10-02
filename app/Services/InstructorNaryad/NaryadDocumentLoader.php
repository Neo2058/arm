<?php

namespace App\Services\InstructorNaryad;

use Illuminate\Support\Facades\Process;
use RuntimeException;

final class NaryadDocumentLoader
{
    /**
     * @return array{label: string, meta: array, assignments: list<array>}
     */
    public static function parseUtf8(string $utf8, string $label): array
    {
        $doc = NaryadParser::parse($utf8);

        return [
            'label' => $label,
            'meta' => $doc['meta'] ?? [],
            'assignments' => $doc['assignments'] ?? [],
        ];
    }

    public static function utf8FromContents(string $bytes, string $name = ''): string
    {
        if (self::looksLikePdf($bytes, $name)) {
            $tmp = tempnam(sys_get_temp_dir(), 'naryadpdf');
            if ($tmp === false) {
                throw new RuntimeException('Не удалось создать временный файл для PDF.');
            }
            $pdfPath = $tmp.'.pdf';
            @unlink($tmp);
            if (file_put_contents($pdfPath, $bytes) === false) {
                throw new RuntimeException('Не удалось записать PDF во временный файл.');
            }
            try {
                return self::extractPdf($pdfPath);
            } finally {
                @unlink($pdfPath);
            }
        }

        return Text::toUtf8Auto($bytes);
    }

    public static function looksLikePdf(string $bytes, string $name = ''): bool
    {
        if (str_starts_with($bytes, '%PDF')) {
            return true;
        }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        return $ext === 'pdf';
    }

    public static function extractPdf(string $absolutePdfPath): string
    {
        if (! is_file($absolutePdfPath)) {
            throw new RuntimeException('PDF не найден на диске');
        }

        $pdftotext = Process::timeout(60)->run([
            'pdftotext', '-layout', '-enc', 'UTF-8', $absolutePdfPath, '-',
        ]);
        if ($pdftotext->successful() && trim($pdftotext->output()) !== '') {
            return $pdftotext->output();
        }

        $script = base_path('scripts/extract_naryad_pdf.py');
        if (! is_file($script)) {
            throw new RuntimeException('Не найден scripts/extract_naryad_pdf.py');
        }

        $python = self::pythonBinary();
        $result = Process::timeout(120)->run([$python, $script, $absolutePdfPath]);
        if ($result->failed() || trim($result->output()) === '') {
            $err = trim($result->errorOutput() ?: $result->output());
            throw new RuntimeException(
                $err !== ''
                    ? $err
                    : 'Не удалось извлечь текст из PDF наряда. Нужны pdftotext или python3+pdfplumber.'
            );
        }

        return $result->output();
    }

    private static function pythonBinary(): string
    {
        foreach (['python3', 'python'] as $bin) {
            $check = Process::timeout(10)->run([$bin, '--version']);
            if ($check->successful()) {
                return $bin;
            }
        }

        throw new RuntimeException('На сервере нет python3. Установите python3 и pdfplumber.');
    }
}
