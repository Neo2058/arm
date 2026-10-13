<?php

namespace App\Http\Controllers;

use App\Models\ActionLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class ActionLogController extends Controller
{
    /**
     * UI-only events from React islands. Server mutations must not go through here.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'action' => ['required', 'string', 'max:100', Rule::in(ActionLog::CLIENT_ACTIONS)],
            'details' => 'nullable|array',
        ]);

        ActionLog::log(
            ActionLog::CLIENT_PREFIX.$validated['action'],
            ActionLog::sanitizeClientDetails($validated['details'] ?? null),
            Auth::id()
        );

        return response()->json(['success' => true]);
    }

    public function index(Request $request)
    {
        abort(403);
    }
}
