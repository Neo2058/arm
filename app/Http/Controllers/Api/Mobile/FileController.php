<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\ActionLog;
use App\Models\Document;
use App\Models\TrainingMaterial;
use App\Services\FileProxyService;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Signed-only file streams for the mobile players (no session, no Sanctum header).
 * Capability is the signature; URLs are issued only after auth+role checks.
 */
class FileController extends Controller
{
    public function __construct(private FileProxyService $files) {}

    public function document(Document $document): StreamedResponse
    {
        $path = $document->file_path;
        if (! $this->files->exists($path)) {
            abort(404, 'Файл не найден');
        }

        ActionLog::log('mobile.view_document', [
            'document_id' => $document->id,
            'title' => $document->title,
        ]);

        $filename = pathinfo($path, PATHINFO_FILENAME).'.pdf';

        return $this->files->respond($path, $filename, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.addslashes($filename).'"',
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function training(TrainingMaterial $material): StreamedResponse
    {
        abort_unless(in_array($material->type, ['video', 'audio'], true), 404);
        abort_unless($material->is_active, 404);

        $path = $material->file_path;
        if (! $this->files->exists($path)) {
            abort(404, 'Файл не найден');
        }

        ActionLog::log('mobile.training_material_viewed', [
            'material_id' => $material->id,
            'title' => $material->title,
            'type' => $material->type,
        ]);

        $filename = $material->file_name ?: basename($path);
        $mime = $material->mime_type
            ?: $this->files->disk()->mimeType($path)
            ?: ($material->type === 'video' ? 'video/mp4' : 'audio/mpeg');

        return $this->files->respond($path, $filename, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline; filename="'.addslashes($filename).'"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
            'X-Content-Type-Options' => 'nosniff',
            'Accept-Ranges' => 'bytes',
        ]);
    }
}
