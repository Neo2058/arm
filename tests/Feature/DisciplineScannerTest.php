<?php

namespace Tests\Feature;

use App\Services\Discipline\ReportScanner;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class DisciplineScannerTest extends TestCase
{
    public function test_scanner_accepts_only_zero_exit_and_fails_closed(): void
    {
        $script = tempnam(sys_get_temp_dir(), 'discipline-scanner-');
        chmod($script, 0700);
        config(['discipline.scanner' => $script, 'discipline.clamd_config' => null]);
        $file = UploadedFile::fake()->createWithContent('report.pdf', "%PDF-1.4\n%%EOF");
        try {
            foreach ([0, 1, 2] as $code) {
                file_put_contents($script, "#!/bin/sh\nexit $code\n");
                try {
                    app(ReportScanner::class)->scan($file);
                    $this->assertSame(0, $code);
                } catch (ValidationException $e) {
                    $this->assertSame(1, $code);
                    $this->assertArrayHasKey('files', $e->errors());
                } catch (HttpException $e) {
                    $this->assertSame(2, $code);
                    $this->assertSame(503, $e->getStatusCode());
                }
            }
            unlink($script);
            $this->expectException(HttpException::class);
            app(ReportScanner::class)->scan($file);
        } finally {
            if (file_exists($script)) {
                unlink($script);
            }
        }
    }
}
