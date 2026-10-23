<?php

namespace App\Services\Discipline;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

class ReportScanner
{
    public function scan(UploadedFile $file): void
    {
        try {
            $command = [config('discipline.scanner'), '--stream', '--no-summary'];
            if (config('discipline.clamd_config')) {
                $command[] = '--config-file='.config('discipline.clamd_config');
            }
            $process = new Process([...$command, '--', $file->getRealPath()]);
            $process->setTimeout(config('discipline.scan_timeout', 60));
            $process->run();
        } catch (\Throwable $e) {
            abort(503, 'Антивирус недоступен. Повторите отправку позже.');
        }
        if ($process->getExitCode() === 1) {
            throw ValidationException::withMessages(['files' => 'Вложение отклонено антивирусом.']);
        }
        abort_unless($process->isSuccessful(), 503, 'Антивирус недоступен. Повторите отправку позже.');
    }
}
