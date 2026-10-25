<?php

namespace App\Services\Antivirus;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

class ClamAvScanner
{
    public function scan(UploadedFile $file, string $errorKey = 'files'): void
    {
        try {
            $command = [config('antivirus.scanner'), '--stream', '--no-summary'];
            if (config('antivirus.clamd_config')) {
                $command[] = '--config-file='.config('antivirus.clamd_config');
            }
            $process = new Process([...$command, '--', $file->getRealPath()]);
            $process->setTimeout(config('antivirus.scan_timeout', 60));
            $process->run();
        } catch (\Throwable $e) {
            abort(503, 'Антивирус недоступен. Повторите отправку позже.');
        }
        if ($process->getExitCode() === 1) {
            throw ValidationException::withMessages([$errorKey => 'Вложение отклонено антивирусом.']);
        }
        abort_unless($process->isSuccessful(), 503, 'Антивирус недоступен. Повторите отправку позже.');
    }
}
