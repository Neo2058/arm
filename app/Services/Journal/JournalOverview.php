<?php

namespace App\Services\Journal;

use App\Models\JournalCrewNormative;
use App\Models\JournalDocument;
use App\Models\JournalTask;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Database\Eloquent\Builder;

class JournalOverview
{
    public function column(User $user): ?string
    {
        return $user->profile?->column ?: null;
    }

    public function tasks(User $user): Builder
    {
        $column = $this->column($user);

        return JournalTask::where('user_id', $user->id)->where('column', $column)
            ->when($column === null, fn ($q) => $q->whereRaw('1 = 0'));
    }

    public function summary(User $user): array
    {
        $column = $this->column($user);
        $stats = ['column_members' => 0, 'tasks_pending' => 0, 'tasks_done' => 0,
            'documents_count' => 0, 'norms_completed' => 0, 'reports_written' => 0];
        if ($column === null) {
            return ['column' => null, 'stats' => $stats, 'error' => 'У вас не указана колонна в профиле. Обратитесь к администратору.'];
        }
        $total = JournalCrewNormative::where('column', $column)->count();
        $done = JournalCrewNormative::where('column', $column)->whereNotNull('last_date')->count();
        $stats = [
            'column_members' => UserProfile::where('column', $column)->count(),
            'tasks_pending' => $this->tasks($user)->where('status', 'pending')->count(),
            'tasks_done' => $this->tasks($user)->where('status', 'done')->count(),
            'documents_count' => JournalDocument::where('user_id', $user->id)->where('column', $column)->count(),
            'norms_completed' => $total > 0 ? (int) round($done * 100 / $total) : 0,
            'reports_written' => $this->tasks($user)->where('source', 'report')->where('status', 'done')->count(),
        ];

        return ['column' => $column, 'stats' => $stats, 'error' => null];
    }
}
