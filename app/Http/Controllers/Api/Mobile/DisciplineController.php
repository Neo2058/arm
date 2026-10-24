<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\DisciplineAssignment;
use App\Models\DisciplineFile;
use App\Models\DisciplineReport;
use App\Services\Discipline\DisciplineAccess;
use App\Services\Discipline\DisciplineService;
use App\Services\FileProxyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\HeaderUtils;

class DisciplineController extends Controller
{
    public const LIMITS = [
        'max_files' => 5, 'max_file_bytes' => 10485760, 'max_total_bytes' => 26214400,
        'allowed_mime_types' => ['application/pdf', 'image/jpeg', 'image/png'],
    ];

    public function __construct(private DisciplineAccess $access) {}

    private function instructor(Request $request): void
    {
        abort_unless($this->access->current($request->user())->isInstructor(), 403);
    }

    private function assignment(Request $request, int $id): DisciplineAssignment
    {
        $this->instructor($request);

        return $this->access->assignments($request->user())->with(['card.author', 'assignee'])->findOrFail($id);
    }

    private function json(array $data)
    {
        return response()->json($data)->header('Cache-Control', 'private, no-store');
    }

    public function index(Request $request)
    {
        $this->instructor($request);
        $input = $request->validate([
            'page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:50',
            'status' => ['nullable', Rule::in(array_keys(DisciplineAssignment::LABELS))], 'overdue' => 'sometimes|boolean',
        ]);
        $base = $this->access->assignments($request->user());
        $unread = DB::table('discipline_notifications')->where('user_id', $request->user()->id)->whereNull('read_at');
        $unreadCount = (clone $unread)->count();
        if ($input['status'] ?? null) {
            $base->where('status', $input['status']);
        }
        if ($request->boolean('overdue')) {
            $base->where('status', '<>', 'accepted')->whereHas('card', fn ($q) => $q->whereDate('due_date', '<', today(config('discipline.timezone'))->toDateString()));
        }
        $page = $base->with(['card.author', 'assignee'])->orderByDesc('updated_at')->orderByDesc('id')->paginate($input['per_page'] ?? 25);

        $unreadIds = (clone $unread)->whereIn('assignment_id', $page->getCollection()->pluck('id'))->pluck('assignment_id')->unique()->all();

        return $this->json([
            'assignments' => $page->getCollection()->map(fn ($a) => $this->payload($a) + ['unread' => in_array($a->id, $unreadIds)])->values(),
            'pagination' => $this->pagination($page), 'unread_count' => $unreadCount, 'limits' => self::LIMITS,
        ]);
    }

    public function show(Request $request, int $assignment)
    {
        $assignment = $this->assignment($request, $assignment);

        return $this->json($this->detail($assignment));
    }

    public function reports(Request $request, int $assignment)
    {
        $request->validate(['page' => 'sometimes|integer|min:1']);
        $assignment = $this->assignment($request, $assignment);

        return $this->json($this->history($assignment));
    }

    public function read(Request $request, int $assignment)
    {
        $assignment = $this->assignment($request, $assignment);
        DB::table('discipline_notifications')->where('user_id', $request->user()->id)->where('assignment_id', $assignment->id)->whereNull('read_at')->update(['read_at' => now()]);

        return $this->json(['ok' => true]);
    }

    public function submit(Request $request, int $assignment, DisciplineService $service)
    {
        $this->assignment($request, $assignment);
        $request->validate([
            'report_files' => 'sometimes|array|max:5', 'report_files.*' => 'file|max:10240',
            'explanation_files' => 'sometimes|array|max:5', 'explanation_files.*' => 'file|max:10240',
            'other_files' => 'sometimes|array|max:5', 'other_files.*' => 'file|max:10240',
        ]);
        $result = $service->submit($request->user(), $assignment, $request->only(['operation_id', 'version', 'response']), [
            'report' => $request->file('report_files', []), 'explanation' => $request->file('explanation_files', []), 'other' => $request->file('other_files', []),
        ]);

        return $this->json(['result' => $result] + $this->detail($this->assignment($request, $assignment)));
    }

    public function file(Request $request, int $file, FileProxyService $proxy)
    {
        $this->instructor($request);
        $file = DisciplineFile::with('report')->findOrFail($file);
        $this->assignment($request, $file->report->assignment_id);

        return $proxy->respond($file->path, $file->name, [
            'Content-Type' => $file->mime,
            'Content-Disposition' => HeaderUtils::makeDisposition('attachment', $file->name, 'attachment'),
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => 'sandbox',
        ]);
    }

    private function payload(DisciplineAssignment $a): array
    {
        return [
            'id' => (string) $a->id, 'card_id' => (string) $a->card_id, 'version' => (string) $a->version,
            'title' => $a->card->title, 'instructions' => $a->card->instructions,
            'due_date' => $a->card->due_date->format('Y-m-d'), 'status' => $a->status,
            'status_label' => DisciplineAssignment::LABELS[$a->status], 'is_overdue' => $a->is_overdue,
            'requirements' => $a->card->only(['require_response', 'require_report_file', 'require_explanation_file']),
            'author' => ['id' => (string) $a->card->created_by, 'name' => $a->card->author->name],
            'assignee' => ['id' => (string) $a->assignee_id, 'name' => $a->assignee->name],
            'accepted_at' => $a->accepted_at?->toISOString(), 'updated_at' => $a->updated_at->toISOString(),
        ];
    }

    private function history(DisciplineAssignment $a): array
    {
        $page = $a->reports()->with(['files', 'reviewer'])->orderByDesc('attempt')->paginate(20);

        return [
            'reports' => $page->getCollection()->map(fn (DisciplineReport $r) => [
                'id' => (string) $r->id, 'attempt' => $r->attempt, 'response' => $r->response,
                'requirements' => $r->requirements, 'submitted_at' => $r->submitted_at->toISOString(),
                'decision' => $r->decision, 'review_comment' => $r->review_comment,
                'reviewed_at' => $r->reviewed_at?->toISOString(), 'reviewer_name' => $r->reviewer?->name,
                'files' => $r->files->map(fn ($f) => [
                    'id' => (string) $f->id, 'name' => $f->name, 'category' => $f->category,
                    'mime' => $f->mime, 'size' => $f->size,
                ])->values(),
            ])->values(), 'reports_pagination' => $this->pagination($page),
        ];
    }

    private function detail(DisciplineAssignment $a): array
    {
        return ['assignment' => $this->payload($a), 'limits' => self::LIMITS] + $this->history($a);
    }

    private function pagination($page): array
    {
        return ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'next_page' => $page->hasMorePages() ? $page->currentPage() + 1 : null];
    }
}
