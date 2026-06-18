<?php

namespace App\Http\Controllers;

use App\Models\Naryad;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class NaryadViewerController extends Controller
{
    public function index()
    {
        $user = auth()->user();
        $userRole = strtolower((string)($user->role->value ?? $user->role));

        $query = Naryad::query();

        // Similar role filtering as documents
        if (!in_array($userRole, ['super_admin', 'admin'])) {
            $query->where(function ($q) use ($userRole) {
                $q->whereJsonContains('allowed_roles', $userRole)
                    ->orWhereNull('allowed_roles');
            });
        }

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

        return view('naryady.index', [
            'naryads' => $naryads,
        ]);
    }

    public function show(Naryad $naryad)
    {
        $user = auth()->user();
        $userRole = strtolower((string)($user->role->value ?? $user->role));

        // Role check
        if (!in_array($userRole, ['super_admin', 'admin']) && $naryad->allowed_roles !== null) {
            if (!in_array($userRole, $naryad->allowed_roles)) {
                abort(403, 'Доступ ограничен.');
            }
        }

        $url = Storage::disk('s3')->temporaryUrl(
            $naryad->file_path,
            now()->addMinutes(30),
            ['ResponseContentDisposition' => 'inline']
        );

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

        $url = Storage::disk('s3')->temporaryUrl(
            $naryad->file_path,
            now()->addMinutes(30),
            ['ResponseContentDisposition' => 'inline']
        );

        return response()->json([
            'url' => $url,
            'title' => $naryad->title,
            'search_term' => $request->input('q'), // фамилия
        ]);
    }
}
