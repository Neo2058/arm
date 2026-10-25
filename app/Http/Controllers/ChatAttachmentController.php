<?php

namespace App\Http\Controllers;

use App\Models\ChatAttachment;
use App\Services\FileProxyService;

class ChatAttachmentController extends Controller
{
    public function __construct(private FileProxyService $files) {}

    public function adminServe()
    {
        $user = auth()->user();
        if (! $user?->isAdmin()) {
            abort(403);
        }

        $path = request('path');
        if (empty($path) && request()->filled('attachment')) {
            $path = ChatAttachment::query()->find(request('attachment'))?->path;
        }
        if (empty($path)) {
            abort(404);
        }

        if (! $this->files->exists($path)) {
            abort(404, 'Файл не найден');
        }

        $filename = basename($path);
        $mime = $this->files->disk()->mimeType($path) ?: 'application/octet-stream';

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
