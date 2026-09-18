<?php

namespace App\Filament\Pages;

use App\Models\ActionLog;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

class UserActivityStats extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationLabel = 'Статистика активности';

    protected static ?string $navigationGroup = 'Администрирование';

    protected static ?int $navigationSort = 99;

    protected static string $view = 'filament.pages.user-activity-stats';

    public static function canAccess(): bool
    {
        return Auth::user()?->isSuperAdmin() ?? false;
    }

    public function getStats(): array
    {
        $total = ActionLog::count();

        $byAction = ActionLog::selectRaw('action, COUNT(*) as count')
            ->groupBy('action')
            ->orderByDesc('count')
            ->pluck('count', 'action')
            ->toArray();

        $topUsers = ActionLog::with('user')
            ->selectRaw('user_id, COUNT(*) as count')
            ->whereNotNull('user_id')
            ->groupBy('user_id')
            ->orderByDesc('count')
            ->limit(10)
            ->get()
            ->map(fn ($row) => [
                'name' => $row->user?->name ?? '—',
                'count' => $row->count,
            ]);

        $last24h = ActionLog::where('created_at', '>=', now()->subDay())->count();

        $last7d = ActionLog::where('created_at', '>=', now()->subDays(7))->count();

        return [
            'total' => $total,
            'last_24h' => $last24h,
            'last_7d' => $last7d,
            'by_action' => $byAction,
            'top_users' => $topUsers,
        ];
    }
}
