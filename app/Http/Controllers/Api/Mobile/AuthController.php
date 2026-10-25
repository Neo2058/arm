<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Chat\ChatEnrollment;
use App\Services\ClickHouseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function __construct(private ChatEnrollment $enrollment) {}

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials)) {
            return response()->json([
                'message' => 'Неверные учётные данные.',
            ], 422);
        }

        $user = Auth::user();

        if (! $user->is_active) {
            Auth::logout();

            return response()->json([
                'message' => 'Аккаунт деактивирован.',
            ], 403);
        }

        $user->tokens()->where('name', 'mobile')->delete();
        $token = $user->createToken('mobile')->plainTextToken;
        $this->enrollment->remember($user);

        return response()->json([
            'token' => $token,
            'user' => $this->payload($user),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->enrollment->remember($user);

        return response()->json([
            'user' => $this->payload($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }
        if ($user) {
            ClickHouseService::log('mobile.logout', $user->id, ['via' => 'token'], $user);
        }

        return response()->json(['ok' => true]);
    }

    private function payload(User $user): array
    {
        $user->loadMissing('profile');
        $column = trim((string) ($user->profile?->column ?? ''));
        $column = $column === '' ? null : $column;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->roleValue(),
            'column' => $column,
            'features' => [
                'chat' => filter_var(config('chat.enabled'), FILTER_VALIDATE_BOOLEAN),
            ],
            'can_manage_column_chats' => $user->isInstructor() && $column !== null,
        ];
    }
}
