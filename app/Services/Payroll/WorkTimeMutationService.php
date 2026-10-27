<?php

namespace App\Services\Payroll;

use App\Models\User;
use App\Models\WorkTimeMutation;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** Transaction receipts are operational state, not an analytics journal. */
class WorkTimeMutationService
{
    public function run(User $actor, string $uuid, string $action, array $payload, Closure $apply): array
    {
        Validator::make(['operation_id' => $uuid], ['operation_id' => 'required|uuid'])->validate();
        $hash = hash('sha256', json_encode($this->canonical($payload), JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $uuid, $action, $hash, $apply) {
            $user = User::whereKey($actor->id)->lockForUpdate()->first();
            abort_unless($user?->is_active, 403);
            $receipt = WorkTimeMutation::where('user_id', $user->id)->where('operation_id', $uuid)->first();
            if ($receipt) {
                abort_unless($receipt->action === $action && hash_equals($receipt->payload_hash, $hash), 409, 'UUID уже использован для другой операции.');

                return $receipt->response;
            }
            $response = $apply($user);
            WorkTimeMutation::create(['user_id' => $user->id, 'operation_id' => $uuid, 'action' => $action, 'payload_hash' => $hash, 'response' => $response]);

            return $response;
        });
    }

    private function canonical(array $data): array
    {
        if (! array_is_list($data)) {
            ksort($data);
        }
        foreach ($data as &$value) {
            if (is_array($value)) {
                $value = $this->canonical($value);
            }
        }

        return $data;
    }
}
