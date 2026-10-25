<?php

namespace App\Services\Chat;

use App\Models\User;

class ChatEnrollment
{
    public function remember(User $user): void
    {
        if ($user->mobile_enrolled_at !== null) {
            return;
        }

        $user->mobile_enrolled_at = now();
        $user->save();
    }
}
