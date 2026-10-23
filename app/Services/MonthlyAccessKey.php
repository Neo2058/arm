<?php

namespace App\Services;

class MonthlyAccessKey
{
    public function sequence(): array
    {
        // Preserve the existing algorithm and server timezone; do not rotate the key during deployment.
        $salt = config('app.barrier_salt', 'you-never-win-in-game');
        $hash = hash('sha256', date('Y').'-'.(int) date('m').'-'.$salt);

        return array_map(fn ($offset) => hexdec(substr($hash, $offset, 2)) % 4, [0, 2, 4, 6]);
    }
}
