<?php

namespace Tests\Unit;

use App\Services\DocumentReglamentService;
use PHPUnit\Framework\TestCase;

class DocumentReglamentServiceTest extends TestCase
{
    public function test_kind_maps_to_violation_action(): void
    {
        $this->assertSame('violation_document_screenshot', DocumentReglamentService::actionForKind('screenshot'));
        $this->assertSame('violation_document_screenshot', DocumentReglamentService::actionForKind('recording'));
        $this->assertSame('violation_document_download', DocumentReglamentService::actionForKind('download'));
        $this->assertSame('violation_document_download', DocumentReglamentService::actionForKind('share'));
        $this->assertSame('violation_document_download', DocumentReglamentService::actionForKind('print'));
    }
}
