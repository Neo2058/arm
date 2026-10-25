<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Enums\ChatMessageType;
use App\Http\Controllers\Controller;
use App\Models\ChatRoom;
use App\Models\User;
use App\Services\Chat\ChatAuthorization;
use App\Services\Chat\ChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ChatController extends Controller
{
    public function __construct(
        private ChatAuthorization $authz,
        private ChatService $chats,
    ) {}

    public function sync(Request $request): JsonResponse
    {
        $this->ensureEnabled();

        return $this->json($this->chats->inbox($this->actor($request)));
    }

    public function show(Request $request, ChatRoom $room): JsonResponse
    {
        $user = $this->guard($request, $room);

        return $this->json($this->chats->roomMeta($user, $room));
    }

    public function messages(Request $request, ChatRoom $room): JsonResponse
    {
        $user = $this->guard($request, $room);
        $data = $request->validate([
            'after_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'before_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return $this->json([
            'messages' => $this->chats->thread(
                $user,
                $room,
                isset($data['after_id']) ? (int) $data['after_id'] : null,
                isset($data['before_id']) ? (int) $data['before_id'] : null,
                (int) ($data['limit'] ?? 50),
            ),
        ]);
    }

    public function store(Request $request, ChatRoom $room): JsonResponse
    {
        $user = $this->guard($request, $room);
        if (in_array($request->input('type'), ['image', 'pdf'], true)) {
            $data = $request->validate([
                'client_id' => ['required', 'uuid'],
                'type' => ['required', 'string', Rule::in(['image', 'pdf'])],
                'body' => ['sometimes', 'nullable', 'string', 'max:500'],
                'file' => ['required', 'file'],
            ]);
            $result = $this->chats->sendAttachment(
                $user,
                $room,
                $data['client_id'],
                ChatMessageType::from($data['type']),
                $request->file('file'),
                (string) ($data['body'] ?? ''),
            );
            $message = $result['message'];

            return $this->json([
                'id' => $message->id,
                'client_id' => $message->client_id,
                'status' => $message->status->value,
            ], $result['created'] ? 202 : 200);
        }

        $data = $request->validate([
            'client_id' => ['required', 'uuid'],
            'type' => ['required', 'string', Rule::in(['text'])],
            'body' => ['required', 'string', 'max:'.(int) config('chat.message_max', 4000)],
        ]);
        $result = $this->chats->sendText($user, $room, $data['client_id'], $data['body']);
        $message = $result['message'];

        return $this->json([
            'id' => $message->id,
            'created_at' => $message->created_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'client_id' => $message->client_id,
            'status' => $message->status->value,
        ], $result['created'] ? 201 : 200);
    }

    public function read(Request $request, ChatRoom $room): JsonResponse
    {
        $user = $this->guard($request, $room);
        $data = $request->validate([
            'last_read_message_id' => ['required', 'integer', 'min:1'],
        ]);
        $this->chats->markRead($user, $room, (int) $data['last_read_message_id']);

        return $this->json(['ok' => true]);
    }

    public function members(Request $request, ChatRoom $room): JsonResponse
    {
        $user = $this->guard($request, $room);

        return $this->json(['members' => $this->chats->members($user, $room)]);
    }

    public function eligible(Request $request, ChatRoom $room): JsonResponse
    {
        $user = $this->guard($request, $room);
        $data = $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        return $this->json([
            'users' => $this->chats->eligible($user, $room, (string) ($data['q'] ?? '')),
        ]);
    }

    public function addMember(Request $request, ChatRoom $room): JsonResponse
    {
        $actor = $this->guard($request, $room);
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);
        $this->chats->addMember($actor, $room, User::query()->with('profile')->findOrFail($data['user_id']));

        return $this->json(['ok' => true], 201);
    }

    public function removeMember(Request $request, ChatRoom $room, User $user): JsonResponse
    {
        $actor = $this->guard($request, $room);
        $this->chats->removeMember($actor, $room, $user);

        return $this->json(['ok' => true]);
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        $user->loadMissing('profile');

        return $user;
    }

    private function guard(Request $request, ChatRoom $room): User
    {
        $this->ensureEnabled();
        $user = $this->actor($request);
        $this->authz->assertMobileRoom($user, $room);

        return $user;
    }

    private function ensureEnabled(): void
    {
        if (! filter_var(config('chat.enabled'), FILTER_VALIDATE_BOOLEAN)) {
            abort(404);
        }
    }

    private function json(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status)->header('Cache-Control', 'private, no-store');
    }
}
