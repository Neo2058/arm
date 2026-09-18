<?php

namespace App\Http\Controllers;

use App\Models\ActionLog;
use App\Models\Naryad;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class NaryadViewerController extends Controller
{
    public function index()
    {
        $user = auth()->user();

        $query = $user->constrainByAllowedRoles(Naryad::query());

        $naryads = $query->orderBy('naryad_date', 'desc')
            ->get()
            ->map(function ($naryad) {
                return [
                    'id' => $naryad->id,
                    'title' => $naryad->title,
                    'naryad_date' => $naryad->naryad_date?->format('d.m.Y'),
                    'naryad_date_iso' => $naryad->naryad_date?->format('Y-m-d'), // for client filtering
                ];
            });

        $isAdmin = $user->isAdmin();

        // Log viewing the naryads list page (the three blocks viewer)
        // Hybrid: goes to relational DB + ClickHouse
        ActionLog::log('view_naryads_page');

        return view('naryady.index', [
            'naryads' => $naryads,
            'isAdmin' => $isAdmin,
        ]);
    }

    public function show(Naryad $naryad)
    {
        $user = auth()->user();

        // Role check
        if (! $user->canAccessByRoles($naryad->allowed_roles)) {
            abort(403, 'Доступ ограничен.');
        }

        $url = (function () use ($naryad) {
            try {
                return Storage::disk('s3')->temporaryUrl(
                    $naryad->file_path,
                    now()->addMinutes(30),
                    ['ResponseContentDisposition' => 'inline']
                );
            } catch (\Throwable $e) {
                \Log::warning('S3 temp url failed for naryad', ['id' => $naryad->id, 'err' => $e->getMessage()]);

                return null;
            }
        })();

        // Log the PDF view action
        // Hybrid: goes to relational DB + ClickHouse via ActionLog::log()
        ActionLog::log('view_naryad', [
            'naryad_id' => $naryad->id,
            'title' => $naryad->title,
            'date' => $naryad->naryad_date?->format('Y-m-d'),
        ]);

        return response()->json([
            'url' => $url,
            'title' => $naryad->title,
            'naryad_date' => $naryad->naryad_date?->format('d.m.Y'),
        ]);
    }

    public function search(Request $request, Naryad $naryad)
    {
        // Placeholder for search - will implement name search + highlight
        // For now, just return the url again or metadata.
        // The actual PDF search/highlight will be in frontend or using pdf.js text layer.

        $url = (function () use ($naryad) {
            try {
                return Storage::disk('s3')->temporaryUrl(
                    $naryad->file_path,
                    now()->addMinutes(30),
                    ['ResponseContentDisposition' => 'inline']
                );
            } catch (\Throwable $e) {
                \Log::warning('S3 temp url failed for naryad', ['id' => $naryad->id, 'err' => $e->getMessage()]);

                return null;
            }
        })();

        return response()->json([
            'url' => $url,
            'title' => $naryad->title,
            'search_term' => $request->input('q'), // фамилия
        ]);
    }
}
