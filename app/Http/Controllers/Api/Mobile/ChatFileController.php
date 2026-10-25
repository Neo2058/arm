<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\ChatAttachment;
use App\Services\FileProxyService;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatFileController extends Controller
{
    public function __construct(private FileProxyService $files) {}

    public function show(ChatAttachment $attachment): StreamedResponse
    {
        if (! filter_var(config('chat.enabled'), FILTER_VALIDATE_BOOLEAN)) {
            abort(404);
        }

        $filename = $attachment->original_name !== ''
            ? $attachment->original_name
            : basename($attachment->path);
        $mime = $attachment->mime !== '' ? $attachment->mime : 'application/octet-stream';

        return $this->files->respond($attachment->path, $filename, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline; filename="'.addslashes($filename).'"',
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
