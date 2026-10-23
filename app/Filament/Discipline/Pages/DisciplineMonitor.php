<?php

namespace App\Filament\Discipline\Pages;

use App\Models\DisciplineCard;
use App\Models\DisciplineReport;
use App\Models\User;
use App\Services\Discipline\DisciplineAccess;
use App\Services\Discipline\DisciplineService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\WithPagination;

class DisciplineMonitor extends Page
{
    use WithPagination;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationLabel = 'Карточки и отчёты';

    protected static ?string $title = 'Исполнительская дисциплина';

    protected static string $view = 'filament.discipline.monitor';

    public array $data = [
        'title' => '', 'instructions' => '', 'due_date' => '', 'all_instructors' => true, 'assignee_ids' => [],
        'require_response' => true, 'require_report_file' => false, 'require_explanation_file' => false,
    ];

    #[Locked]
    public string $operationId;

    public string $state = '';

    public string $cardId = '';

    public static function canAccess(): bool
    {
        return auth()->user()?->fresh()?->canManageDiscipline() ?? false;
    }

    public function mount(): void
    {
        app(DisciplineAccess::class)->manage(auth()->user());
        $this->operationId = (string) Str::uuid();
    }

    public function createCard(): void
    {
        app(DisciplineService::class)->create(auth()->user(), $this->data + ['operation_id' => $this->operationId]);
        $this->reset('data');
        $this->operationId = (string) Str::uuid();
        Notification::make()->title('Карточка создана, поручения выданы')->success()->send();
    }

    public function updatedState(): void
    {
        $this->resetPage();
    }

    public function updatedCardId(): void
    {
        $this->resetPage();
    }

    protected function getViewData(): array
    {
        $user = app(DisciplineAccess::class)->manage(auth()->user());
        $base = app(DisciplineAccess::class)->assignments($user);
        $cards = DisciplineCard::whereIn('id', (clone $base)->select('card_id'))->latest('id')->get(['id', 'title']);
        if ($this->cardId !== '') {
            $base->where('card_id', $this->cardId);
        }
        $counts = (clone $base)->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');
        $overdue = (clone $base)->where('status', '<>', 'accepted')->whereHas('card', fn ($q) => $q->whereDate('due_date', '<', today(config('discipline.timezone'))->toDateString()))->count();
        $reports = DisciplineReport::whereIn('assignment_id', (clone $base)->select('id'))
            ->with('assignment.assignee', 'assignment.card')->latest('id')->limit(30)->get();
        if ($this->state !== '') {
            $base->where('status', $this->state);
        }

        return [
            'assignments' => $base->with(['card', 'assignee'])->latest('updated_at')->orderByDesc('id')->paginate(30),
            'counts' => $counts, 'overdue' => $overdue, 'reports' => $reports, 'cards' => $cards,
            'unreadCount' => DB::table('discipline_notifications')->where('user_id', $user->id)->whereNull('read_at')->count(),
            'instructors' => User::where('role', 'instructor')->where('is_active', true)->with('profile')->orderBy('name')->get(),
        ];
    }
}
