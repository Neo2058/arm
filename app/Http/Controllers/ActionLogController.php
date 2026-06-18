<?php

namespace App\Http\Controllers;

use App\Models\ActionLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ActionLogController extends Controller
{
    /**
     * Store a user action log (called from frontend and backend).
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'action'  => 'required|string|max:100',
            'details' => 'nullable|array',
        ]);

        // This will write to BOTH relational DB (ActionLog table) and ClickHouse (hybrid)
        ActionLog::log(
            $validated['action'],
            $validated['details'] ?? null,
            Auth::id()
        );

        return response()->json(['success' => true]);
    }

    /**
     * Optional: list for API if needed (admin only later).
     */
    public function index(Request $request)
    {
        // Will be handled primarily by Filament
        abort(403);
    }
}
