<?php

namespace App\Services\Discipline;

use App\Services\Antivirus\ClamAvScanner;
use Illuminate\Http\UploadedFile;

class ReportScanner
{
    public function __construct(private ClamAvScanner $scanner) {}

    public function scan(UploadedFile $file): void
    {
        $this->scanner->scan($file, 'files');
    }
}
