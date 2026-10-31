<?php

namespace App\Http\Controllers\Instructor;

use App\Http\Controllers\Controller;
use App\Models\JournalCrewNormative;
use App\Models\JournalNormativeSetting;
use App\Services\Journal\InstructorTrainingAccounting;
use App\Services\Journal\InstructorTrainingRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TrainingRequestController extends Controller
{
    public function index(Request $request, InstructorTrainingRequests $service)
    {
        $actor = $request->user()->fresh();
        $column = $service->column($actor);
        $data = $request->validate(['month' => 'nullable|date_format:Y-m']);
        $month = $data['month'] ?? now()->format('Y-m');
        $cards = DB::table('instructor_training_requests')->where('instructor_id', $actor->id)->where('column', $column)->where('planning_month', $month.'-01')->orderBy('id')->get();
        $members = DB::table('instructor_training_members')->join('users', 'users.id', '=', 'instructor_training_members.user_id')->whereIn('request_id', $cards->pluck('id'))->select('request_id', 'user_id', 'users.name')->get()->groupBy('request_id');
        $used = DB::table('instructor_training_requests')->where('instructor_id', $actor->id)->where('planning_month', $month.'-01')->count();

        $completions = DB::table('instructor_training_completions')->where('instructor_id', $actor->id)->where('column', $column)->whereIn('request_id', $cards->pluck('id'))->get()->keyBy('request_id');
        $attendance = DB::table('instructor_training_attendance')->whereIn('completion_id', $completions->pluck('id'))->get()->groupBy('completion_id');
        $atzNorms = JournalCrewNormative::where('instructor_id', $actor->id)->where('column', $column)->whereIn('type', ['atz', 'atz_line'])->get()->groupBy('user_id');
        $setting = JournalNormativeSetting::where('user_id', $actor->id)->first();

        return view('journal.training-requests.index', compact('cards', 'members', 'month', 'column', 'used', 'completions', 'attendance', 'setting', 'atzNorms') + ['crew' => $service->crew($column)->get()]);
    }

    public function store(Request $request, InstructorTrainingRequests $service)
    {
        return $this->save($request, $service, 'create', null);
    }

    public function update(Request $request, int $trainingRequest, InstructorTrainingRequests $service)
    {
        return $this->save($request, $service, 'update', $trainingRequest);
    }

    public function destroy(Request $request, int $trainingRequest, InstructorTrainingRequests $service)
    {
        return $this->save($request, $service, 'delete', $trainingRequest);
    }

    public function complete(Request $request, int $trainingRequest, InstructorTrainingAccounting $service)
    {
        $result = $service->complete($request->user(), $trainingRequest, $request->all());
        if ($request->expectsJson()) {
            return response()->json($result);
        }

        return back()->with('success', 'Занятие учтено для присутствовавших машинистов.');
    }

    public function periods(Request $request, InstructorTrainingAccounting $service)
    {
        $result = $service->periods($request->user(), $request->all());
        if ($request->expectsJson()) {
            return response()->json($result);
        }

        return back()->with('success', 'Периодичность сохранена. Следующие сроки АТЗ пересчитаны.');
    }

    private function save(Request $request, InstructorTrainingRequests $service, string $action, ?int $id)
    {
        $result = $service->mutate($request->user(), $action, $id, $request->all());
        if ($request->expectsJson()) {
            return response()->json($result);
        }

        return redirect()->route('journal.training-requests.index', ['month' => $request->input('planning_month') ?? now()->format('Y-m')])->with('success', $action === 'delete' ? 'Карточка удалена.' : 'Карточка сохранена.');
    }
}
