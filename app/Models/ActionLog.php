<?php

namespace App\Models;

use App\Services\ClickHouseService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;

class ActionLog extends Model
{
    protected $fillable = [
        'user_id',
        'action',
        'details',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'details' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Helper to create a log entry.
     * Writes to BOTH relational DB (for Filament UI) and ClickHouse (for analytics).
     * Hybrid approach.
     */
    public static function log(string $action, ?array $details = null, ?int $userId = null): self
    {
        $request = request();
        $userId = $userId ?? auth()->id();

        // 1. Write to relational database (for convenient Filament queries, filters, relations)
        $log = self::create([
            'user_id'    => $userId,
            'action'     => $action,
            'details'    => $details,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
        ]);

        // 2. Also write to ClickHouse (consistent with the rest of the project analytics)
        try {
            $resourceId = 0;
            if (is_array($details)) {
                $resourceId = $details['id']
                    ?? $details['naryad_id']
                    ?? $details['resource_id']
                    ?? 0;
            }

            // Convert details to string for ClickHouse if needed
            $chDetails = $details;
            if (is_array($chDetails)) {
                $chDetails = json_encode($chDetails, JSON_UNESCAPED_UNICODE);
            }

            ClickHouseService::log($action, (int) $resourceId, $chDetails ?: '');
        } catch (\Throwable $e) {
            // Never break the main flow if ClickHouse is unavailable
            Log::warning('Failed to log action to ClickHouse', [
                'action' => $action,
                'error'  => $e->getMessage(),
            ]);
        }

        return $log;
    }
}
