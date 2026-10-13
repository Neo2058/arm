<?php

namespace App\Models;

use App\Services\ClickHouseService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;

class ActionLog extends Model
{
    public const CLIENT_PREFIX = 'ui.';

    /**
     * Actions the browser may POST to /api/actions/log. Stored as ui.{action}.
     *
     * @var list<string>
     */
    public const CLIENT_ACTIONS = [
        'search_phones',
        'search_explanations',
        'view_naryads_tab',
        'view_phones_tab',
        'view_explanations_tab',
        'open_naryad',
        'add_phone',
        'delete_phone',
        'add_explanation',
        'delete_explanation',
        'search_naryad_pdf',
    ];

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
     * Operational log (Postgres for Filament) + one ClickHouse analytics row.
     * Callers must not also invoke ClickHouseService::log() for the same event.
     */
    public static function log(string $action, ?array $details = null, ?int $userId = null): self
    {
        $request = request();
        $userId = $userId ?? auth()->id();

        $log = self::create([
            'user_id' => $userId,
            'action' => $action,
            'details' => $details,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
        ]);

        try {
            ClickHouseService::log($action, self::resourceIdFromDetails($details), $details ?? '', $userId);
        } catch (\Throwable $e) {
            Log::warning('Failed to log action to ClickHouse', [
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
        }

        return $log;
    }

    /**
     * @param  array<string, mixed>|null  $details
     */
    public static function resourceIdFromDetails(?array $details): int
    {
        if (! is_array($details)) {
            return 0;
        }

        foreach (['id', 'naryad_id', 'document_id', 'material_id', 'resource_id'] as $key) {
            if (isset($details[$key]) && is_numeric($details[$key])) {
                return (int) $details[$key];
            }
        }

        return 0;
    }

    /**
     * @param  array<mixed>|null  $details
     * @return array<string, bool|int|float|string|null>|null
     */
    public static function sanitizeClientDetails(?array $details): ?array
    {
        if ($details === null) {
            return null;
        }

        $clean = [];
        $n = 0;
        foreach ($details as $key => $value) {
            if ($n >= 20) {
                break;
            }
            if (! is_string($key) || strlen($key) > 64) {
                continue;
            }
            if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
                $clean[$key] = $value;
                $n++;
                continue;
            }
            if (is_string($value)) {
                $clean[$key] = mb_substr($value, 0, 500);
                $n++;
            }
        }

        return $clean;
    }
}
