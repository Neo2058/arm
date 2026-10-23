<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DisciplineAssignment extends Model
{
    protected $guarded = [];

    protected $casts = ['accepted_at' => 'datetime', 'version' => 'integer'];

    public const LABELS = ['assigned' => 'Ожидает отчёта', 'in_review' => 'На проверке', 'needs_revision' => 'На доработке', 'accepted' => 'Принято'];

    public function card()
    {
        return $this->belongsTo(DisciplineCard::class, 'card_id');
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function reports()
    {
        return $this->hasMany(DisciplineReport::class, 'assignment_id');
    }

    public function getIsOverdueAttribute(): bool
    {
        return $this->status !== 'accepted' && $this->card->due_date->toDateString() < today(config('discipline.timezone'))->toDateString();
    }
}
