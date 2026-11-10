<?php

namespace App\Services\Chat;

use App\Models\User;

class ChatEnrollment
{
    public function remember(User $user): void
    {
        try {
            if ($user->mobile_enrolled_at !== null) {
                return;
            }

            $user->mobile_enrolled_at = now();
            $user->save();
        } catch (\Throwable) {
            // Login and /me stay available if enrollment persistence fails.
        }
    }
}
