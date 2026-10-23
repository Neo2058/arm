<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\ActionLog;
use App\Models\Naryad;
use App\Models\NaryadPersonFile;
use App\Services\FileProxyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class NaryadController extends Controller
{
    public function __construct(private FileProxyService $files) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $personFiles = NaryadPersonFile::query()
            ->where('user_id', $user->id)
            ->get()
            ->keyBy('naryad_id');

        $naryads = $user->constrainByAllowedRoles(Naryad::query())
            ->orderByDesc('naryad_date')
            ->orderByDesc('id')
            ->get()
            ->map(function (Naryad $naryad) use ($personFiles) {
                return [
                    'id' => $naryad->id,
                    'title' => $naryad->title,
                    'naryad_date' => $naryad->naryad_date?->format('d.m.Y'),
                    'has_pdf' => $naryad->hasGeneralPdf(),
                    'has_personal' => $personFiles->has($naryad->id),
                ];
            })
            ->values();

        return response()->json(['naryads' => $naryads]);
    }

    public function show(Request $request, Naryad $naryad): JsonResponse
    {
        $user = $request->user();
        if (! $user->canAccessByRoles($naryad->allowed_roles)) {
            abort(403, 'Доступ ограничен.');
        }

        $mine = NaryadPersonFile::query()
            ->where('naryad_id', $naryad->id)
            ->where('user_id', $user->id)
            ->first();

        $personalText = '';
        if ($mine && Storage::disk('s3')->exists($mine->file_path)) {
            $personalText = (string) Storage::disk('s3')->get($mine->file_path);
        }

        $url = null;
        if ($naryad->hasGeneralPdf()) {
            $url = $this->files->temporarySignedRoute('mobile.files.naryads', 30, $naryad->id);
        }

        ActionLog::log('mobile.view_naryad', [
            'naryad_id' => $naryad->id,
            'title' => $naryad->title,
        ]);

        return response()->json([
            'id' => $naryad->id,
            'title' => $naryad->title,
            'naryad_date' => $naryad->naryad_date?->format('d.m.Y'),
            'personal_name' => $mine?->file_name,
            'personal_text' => $personalText,
            'url' => $url,
        ]);
    }
}
