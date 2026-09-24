<?php

namespace App\Services\InstructorNaryad;

use Illuminate\Support\Facades\Process;
use RuntimeException;

final class BreakdownPdfExtractor
{
    public static function isPdf(string $path, string $originalName = ''): bool
    {
        $ext = strtolower(pathinfo($originalName !== '' ? $originalName : $path, PATHINFO_EXTENSION));
        if ($ext === 'pdf') {
            return true;
        }
        $head = @file_get_contents($path, false, null, 0, 5);

        return is_string($head) && str_starts_with($head, '%PDF');
    }

    public static function inferKindFromName(string $filename): ?string
    {
        $blob = mb_strtolower($filename, 'UTF-8');
        if (str_contains($blob, 'выходн') || str_contains($blob, 'субботн') || str_contains($blob, 'воскресн')) {
            return 'weekend';
        }
        if (str_contains($blob, 'рабоч')) {
            return 'work';
        }

        return null;
    }

    /**
     * @param  'work'|'weekend'|'auto'|null  $kind
     */
    public static function extract(string $absolutePdfPath, ?string $kind = 'auto'): string
    {
        $script = base_path('scripts/extract_shifts.py');
        if (! is_file($script)) {
            throw new RuntimeException('Не найден scripts/extract_shifts.py');
        }
        if (! is_file($absolutePdfPath)) {
            throw new RuntimeException('PDF не найден на диске');
        }

        $python = self::pythonBinary();
        $kindArg = in_array($kind, ['work', 'weekend'], true) ? $kind : 'auto';

        $result = Process::timeout(180)->run([
            $python,
            $script,
            '--pdf',
            $absolutePdfPath,
            '--kind',
            $kindArg,
        ]);

        if ($result->failed()) {
            $err = trim($result->errorOutput() ?: $result->output());
            throw new RuntimeException(
                $err !== ''
                    ? $err
                    : 'Не удалось разобрать PDF разбивки. Нужны python3 и pdfplumber.'
            );
        }

        $text = $result->output();
        if (trim($text) === '' || ! str_contains($text, 'SHIFT')) {
            throw new RuntimeException('PDF разобран, но смены не найдены. Проверьте, что это файл разбивки графика.');
        }

        return $text;
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
