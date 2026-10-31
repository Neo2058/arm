<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\JournalNormativeSetting;
use App\Services\Journal\InstructorTrainingAccounting;
use App\Services\Journal\InstructorTrainingRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TrainingRequestController extends Controller
{
    private function json(array $data)
    {
        return response()->json($data)->header('Cache-Control', 'private, no-store');
    }

    public function index(Request $request, InstructorTrainingRequests $service)
    {
        $actor = $request->user()->fresh();
        $column = $service->column($actor);
        $data = $request->validate(['month' => 'required|date_format:Y-m']);
        $cards = DB::table('instructor_training_requests')->where('instructor_id', $actor->id)->where('column', $column)->where('planning_month', $data['month'].'-01')->orderBy('id')->get();
        $members = DB::table('instructor_training_members')->join('users', 'users.id', '=', 'user_id')->whereIn('request_id', $cards->pluck('id'))->select('request_id', 'user_id', 'users.name')->get()->groupBy('request_id');
        $completed = DB::table('instructor_training_completions')->where('instructor_id', $actor->id)->where('column', $column)->whereIn('request_id', $cards->pluck('id'))->get()->keyBy('request_id');
        $attendance = DB::table('instructor_training_attendance')->whereIn('completion_id', $completed->pluck('id'))->get()->groupBy('completion_id');
        $setting = JournalNormativeSetting::where('user_id', $actor->id)->first();

        return $this->json(['column' => $column, 'month' => $data['month'], 'used' => DB::table('instructor_training_requests')->where('instructor_id', $actor->id)->where('planning_month', $data['month'].'-01')->count(), 'limit' => 10, 'periods' => ['atz_months' => (int) ($setting?->atz_months ?? 12), 'atz_line_months' => (int) ($setting?->atz_line_months ?? 12)], 'cards' => $cards->map(function ($card) use ($members, $completed, $attendance) {
            $c = $completed->get($card->id);

            return ['id' => (string) $card->id, 'version' => (string) $card->version, 'planning_month' => substr($card->planning_month, 0, 7), 'shift_type' => $card->shift_type, 'participant_count' => (int) $card->participant_count, 'participants' => $members->get($card->id, collect())->map(fn ($m) => ['id' => (string) $m->user_id, 'name' => $m->name])->values(), 'completion' => $c ? ['id' => (string) $c->id, 'performed_on' => $c->performed_on, 'normative_type' => $c->normative_type, 'period_months' => $c->period_months, 'attendees' => $attendance->get($c->id, collect())->map(fn ($a) => ['id' => (string) $a->user_id, 'name' => $a->name, 'tab_number' => $a->tab_number])->values()] : null];
        })->values()->all()]);
    }

    public function crew(Request $request, InstructorTrainingRequests $service)
    {
        $column = $service->column($request->user()->fresh());
        $data = $request->validate(['page' => 'sometimes|integer|min:1']);
        $page = $service->crew($column)->paginate(50);

        return $this->json(['column' => $column, 'crew' => $page->getCollection()->map(fn ($u) => ['id' => (string) $u->id, 'name' => $u->name, 'tab_number' => $u->profile?->tab_number])->all(), 'next_page' => $page->hasMorePages() ? $page->currentPage() + 1 : null]);
    }

    public function store(Request $r, InstructorTrainingRequests $s)
    {
        return $this->json($s->mutate($r->user(), 'create', null, $r->all()));
    }

    public function update(Request $r, int $trainingRequest, InstructorTrainingRequests $s)
    {
        return $this->json($s->mutate($r->user(), 'update', $trainingRequest, $r->all()));
    }

    public function destroy(Request $r, int $trainingRequest, InstructorTrainingRequests $s)
    {
        return $this->json($s->mutate($r->user(), 'delete', $trainingRequest, $r->all()));
    }

    public function complete(Request $r, int $trainingRequest, InstructorTrainingAccounting $s)
    {
        return $this->json($s->complete($r->user(), $trainingRequest, $r->all()));
    }

    public function periods(Request $r,InstructorTrainingAccounting $s)
    {
        return $this->json($s->periods($r->user(),$r->all()));
    }
}
