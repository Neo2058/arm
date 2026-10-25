<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\JournalCrewNormative;
use App\Models\User;
use App\Services\Journal\JournalOverview;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class JournalController extends Controller
{
    public const LABELS = [
        'kip_linia' => 'КИП Линия', 'kip_manevry' => 'КИП Манёвры', 'kip_podem' => 'КИП Подъём',
        'kip_kru' => 'КИП КРУ', 'kip_ars_r' => 'КИП АРС-Р', 'kip_pnevmatika' => 'КИП Пневматика',
        'kip_scep' => 'КИП Сцеп', 'atz' => 'АТЗ', 'atz_line' => 'АТЗ на Линии',
    ];

    public function __construct(private JournalOverview $overview) {}

    private function instructor(Request $request): User
    {
        $user = $request->user()->fresh();
        abort_unless($user && $user->is_active && $user->isInstructor(), 403);

        return $user;
    }

    private function json(array $data)
    {
        return response()->json($data)->header('Cache-Control', 'private, no-store');
    }

    private function paging(Request $request): array
    {
        return $request->validate(['page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:50']);
    }

    private function pagination($page): array
    {
        return ['page' => $page->currentPage(), 'next_page' => $page->hasMorePages() ? $page->currentPage() + 1 : null, 'total' => $page->total()];
    }

    public function summary(Request $request)
    {
        return $this->json($this->overview->summary($this->instructor($request)));
    }

    public function tasks(Request $request)
    {
        $user = $this->instructor($request);
        $input = $this->paging($request);
        $filter = $request->validate(['status' => ['nullable', Rule::in(['pending', 'done'])]]);
        $query = $this->overview->tasks($user);
        if ($filter['status'] ?? null) {
            $query->where('status', $filter['status']);
        }
        $page = $query->orderBy('status')->orderBy('due_date')->orderBy('id')->paginate($input['per_page'] ?? 25);

        return $this->json([
            'column' => $this->overview->column($user),
            'tasks' => $page->getCollection()->map(fn ($task) => [
                'id' => (string) $task->id, 'title' => $task->title, 'description' => $task->description,
                'status' => $task->status, 'source' => $task->source, 'due_date' => $task->due_date?->format('Y-m-d'),
                'is_overdue' => $task->status === 'pending' && $task->due_date && $task->due_date->lt(today()),
            ])->values(), 'pagination' => $this->pagination($page),
        ]);
    }

    public function crew(Request $request)
    {
        $user = $this->instructor($request);
        $input = $this->paging($request);
        $column = $this->overview->column($user);
        $page = User::where('role', UserRole::DRIVER)->whereHas('profile', fn ($q) => $q->where('column', $column))
            ->when($column === null, fn ($q) => $q->whereRaw('1 = 0'))->with('profile')->orderBy('name')->orderBy('id')->paginate($input['per_page'] ?? 25);
        $norms = JournalCrewNormative::where('instructor_id', $user->id)->where('column', $column)
            ->whereIn('user_id', $page->getCollection()->pluck('id'))->get()->groupBy('user_id');
        $crew = $page->getCollection()->map(function ($member) use ($norms) {
            $records = $norms->get($member->id, collect())->keyBy('type');

            return ['id' => (string) $member->id, 'name' => $member->name, 'class' => $member->profile->normative_class,
                'normatives' => collect(self::LABELS)->map(function ($label, $type) use ($records) {
                    $norm = $records->get($type);

                    return ['type' => $type, 'label' => $label, 'recorded' => $norm !== null,
                        'last_date' => $norm?->last_date?->format('Y-m-d'), 'next_date' => $norm?->next_date?->format('Y-m-d'),
                        'is_overdue' => $norm?->next_date ? $norm->next_date->lt(today()) : false,
                    ];
                })->values()];
        })->values();

        return $this->json(['column' => $column, 'crew' => $crew, 'pagination' => $this->pagination($page)]);
    }
}
