<?php

namespace App\Services\Discipline;

use App\Enums\UserRole;
use App\Models\DisciplineAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class DisciplineAccess
{
    public function current(User $user): User
    {
        $current = $user->fresh();
        abort_unless($current?->is_active, 403, 'Аккаунт недоступен.');

        return $current;
    }

    public function assignments(User $user): Builder
    {
        $user = $this->current($user);
        $query = DisciplineAssignment::query();
        if ($user->roleEnum() === UserRole::NACHALNIK) {
            return $query;
        }
        if ($user->roleEnum() === UserRole::TORMOZNIK) {
            return $query->whereHas('card', fn (Builder $q) => $q->where('created_by', $user->id));
        }
        abort_unless($user->isInstructor(), 403);

        return $query->where('assignee_id', $user->id);
    }

    public function view(User $user, int $id): DisciplineAssignment
    {
        return $this->assignments($user)->with(['card.author', 'assignee', 'reports.files', 'reports.reviewer'])->findOrFail($id);
    }

    public function manage(User $user): User
    {
        $user = $this->current($user);
        abort_unless($user->canManageDiscipline(), 403);

        return $user;
    }
}
