<?php

namespace App\Http\Controllers;

use App\Services\Notifications\UserNotifications;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NotificationController extends Controller
{
    public function __construct(private UserNotifications $notifications) {}

    public function index(Request $request): View
    {
        $this->ensureEnabled();
        $inbox = $this->notifications->inbox($request->user(), false, 100);

        return view('notifications.index', [
            'inbox' => $inbox,
            'vapidPublic' => (string) config('notifications.vapid.public_key'),
        ]);
    }

    public function inbox(Request $request): JsonResponse
    {
        $this->ensureEnabled();
        $unreadOnly = $request->boolean('unread', true);

        return $this->json($this->notifications->inbox($request->user(), $unreadOnly, 50));
    }

    public function read(Request $request, int $notification): JsonResponse|RedirectResponse
    {
        $this->ensureEnabled();
        $row = $this->notifications->markRead($request->user(), $notification);
        abort_if($row === null, 404);
        if ($request->expectsJson()) {
            return $this->json(['ok' => true, 'item' => $row->payload()]);
        }

        return back();
    }

    public function readAll(Request $request): JsonResponse|RedirectResponse
    {
        $this->ensureEnabled();
        $updated = $this->notifications->markAllRead($request->user());
        if ($request->expectsJson()) {
            return $this->json(['ok' => true, 'updated' => $updated]);
        }

        return back();
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

    public function unsubscribe(Request $request): JsonResponse
    {
        $this->ensureEnabled();
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:512'],
        ]);
        $this->notifications->forgetSubscription($request->user(), $data['endpoint']);

        return $this->json(['ok' => true]);
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
