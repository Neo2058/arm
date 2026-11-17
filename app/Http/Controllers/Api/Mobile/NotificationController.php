<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Services\Notifications\UserNotifications;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function __construct(private UserNotifications $notifications) {}

    public function index(Request $request): JsonResponse
    {
        $this->ensureEnabled();
        $unreadOnly = $request->boolean('unread', true);

        return $this->json($this->notifications->inbox($request->user(), $unreadOnly, 50));
    }

    public function read(Request $request, int $notification): JsonResponse
    {
        $this->ensureEnabled();
        $row = $this->notifications->markRead($request->user(), $notification);
        abort_if($row === null, 404);

        return $this->json(['ok' => true, 'item' => $row->payload()]);
    }

    public function readAll(Request $request): JsonResponse
    {
        $this->ensureEnabled();
        $updated = $this->notifications->markAllRead($request->user());

        return $this->json(['ok' => true, 'updated' => $updated]);
    }

    public function subscribe(Request $request): JsonResponse
    {
        $this->ensureEnabled();
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:512'],
            'keys' => ['sometimes', 'array'],
            'keys.p256dh' => ['sometimes', 'nullable', 'string', 'max:191'],
            'keys.auth' => ['sometimes', 'nullable', 'string', 'max:191'],
        ]);
        $this->notifications->saveWebSubscription($request->user(), $data, $request->userAgent());

        return $this->json(['ok' => true], 201);
    }

    private function ensureEnabled(): void
    {
        abort_unless($this->notifications->enabled(), 404);
    }

    private function json(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status)->header('Cache-Control', 'private, no-store');
    }
}
