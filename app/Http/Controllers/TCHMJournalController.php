<?php

namespace App\Http\Controllers;

use App\Models\JournalCrewNormative;
use App\Models\JournalDocument;
use App\Models\JournalNormativeSetting;
use App\Models\JournalTask;
use App\Models\JournalVacation;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\ClickHouseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class TCHMJournalController extends Controller
{
    public function index()
    {
        $user = Auth::user();
        $role = strtolower($user->role->value ?? $user->role);

        if ($role !== 'instructor') {
            abort(403, 'Доступ только для инструкторов (ТЧМ).');
        }

        $profile = $user->profile;

        if (!$profile || !$profile->column) {
            return view('journal.index', [
                'column' => 'Не указана',
                'tasks' => collect(),
                'documents' => collect(),
                'stats' => $this->getDefaultStats(),
                'error' => 'У вас не указана колонна в профиле. Обратитесь к администратору.',
            ]);
        }

        $column = $profile->column;

        // Get column members for stats (drivers/instructors in this column)
        $columnMembers = UserProfile::where('column', $column)->count();

        $tasks = JournalTask::where('user_id', $user->id)
            ->where('column', $column)
            ->orderBy('status')
            ->orderBy('due_date')
            ->get();

        $documents = JournalDocument::where('user_id', $user->id)
            ->where('column', $column)
            ->latest()
            ->get();

        $stats = [
            'column_members' => $columnMembers,
            'tasks_pending' => $tasks->where('status', 'pending')->count(),
            'tasks_done' => $tasks->where('status', 'done')->count(),
            'documents_count' => $documents->count(),
            'norms_completed' => rand(45, 92), // demo
            'reports_written' => rand(12, 38),
        ];

        return view('journal.index', compact('column', 'tasks', 'documents', 'stats'));
    }

    public function addTodo(Request $request)
    {
        $user = Auth::user();
        $profile = $user->profile;

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'due_date' => 'nullable|date',
            'source' => 'nullable|string',
        ]);

        JournalTask::create([
            'user_id' => $user->id,
            'column' => $profile?->column,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'due_date' => $validated['due_date'] ?? null,
            'source' => $validated['source'] ?? 'manual',
            'status' => 'pending',
        ]);

        return redirect()->route('journal.index')->with('success', 'Задача добавлена в журнал.');
    }

    public function completeTodo($id)
    {
        $task = JournalTask::where('user_id', Auth::id())->findOrFail($id);
        $task->update(['status' => 'done']);

        // Log to ClickHouse for history and analysis
        try {
            \App\Services\ClickHouseService::log('normative_completed', $task->user_id, [
                'type' => $task->source,
                'title' => $task->title,
                'column' => $task->column,
            ]);
        } catch (\Exception $e) {
            // ignore if CH not available
        }

        return redirect()->route('journal.index')->with('success', 'Задача отмечена выполненной.');
    }

    public function updateTodo(Request $request, $id)
    {
        $user = Auth::user();
        $task = JournalTask::where('user_id', $user->id)->findOrFail($id);

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'due_date' => 'nullable|date',
        ]);

        $task->update([
            'title' => $validated['title'],
            'description' => $validated['description'] ?? $task->description,
            'due_date' => $validated['due_date'] ?? $task->due_date,
        ]);

        return redirect()->route('journal.index')->with('success', 'Задача обновлена.');
    }

    public function deleteTodo($id)
    {
        $task = JournalTask::where('user_id', Auth::id())->findOrFail($id);
        $taskName = $task->title;
        $task->delete();

        return redirect()->route('journal.index')->with('success', "Задача «{$taskName}» удалена.");
    }

    public function uploadDocument(Request $request)
    {
        $user = Auth::user();
        $profile = $user->profile;

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'pdf' => 'required|file|mimes:pdf|max:10240',
        ]);

        $file = $request->file('pdf');
        $path = $file->store('journal-documents/' . $user->id, 'local'); // or 's3'

        JournalDocument::create([
            'user_id' => $user->id,
            'column' => $profile?->column,
            'title' => $validated['title'],
            'file_path' => $path,
            'extracted_text' => null, // For demo, we'll handle extraction on ask
        ]);

        return redirect()->route('journal.index')->with('success', 'Документ загружен. Теперь можно задавать вопросы.');
    }

    public function askDocument(Request $request)
    {
        $user = Auth::user();

        $validated = $request->validate([
            'document_id' => 'required|exists:journal_documents,id',
            'question' => 'required|string|max:500',
        ]);

        $doc = JournalDocument::where('user_id', $user->id)->findOrFail($validated['document_id']);

        // For demo: simple text-based "AI" using extracted or mock
        // In real would use LLM + embeddings. Here we simulate based on example.
        $answer = $this->generateDemoAnswer($validated['question'], $doc);

        // Store last Q&A in session for immediate add to todo
        session(['last_qa' => [
            'question' => $validated['question'],
            'answer' => $answer,
            'document_title' => $doc->title,
        ]]);

        return redirect()->route('journal.index')->with('qa_result', [
            'question' => $validated['question'],
            'answer' => $answer,
            'doc_id' => $doc->id,
        ]);
    }

    private function generateDemoAnswer(string $question, JournalDocument $doc): string
    {
        $q = mb_strtolower($question);

        // Demo logic based on user's example
        if (str_contains($q, 'светиков') || str_contains($q, 'тчм')) {
            return 'ТЧМ Светикову до 21.07.2026 года провести ДОП КИП с машинистом Степановым А.Г. и отразить результаты работы в рабочем журнале.';
        }

        if (str_contains($q, 'норматив') || str_contains($q, 'задач')) {
            return 'По загруженному документу определены следующие задачи: провести ДОП, заполнить рапорт, отчитаться по колонне до указанной даты.';
        }

        // Fallback simple extraction simulation
        $text = $doc->extracted_text ?? 'В документе указаны задачи по проведению инструктажей и отчётности для ТЧМ.';
        $snippet = mb_substr($text, 0, 200);

        return "На основе документа '{$doc->title}': {$snippet}... (рекомендуется добавить в TODO для контроля).";
    }

    private function getDefaultStats(): array
    {
        return [
            'column_members' => 0,
            'tasks_pending' => 0,
            'tasks_done' => 0,
            'documents_count' => 0,
            'norms_completed' => 0,
            'reports_written' => 0,
        ];
    }

    // ==================== НАСТРОЙКА НОРМАТИВОВ ====================
    public function settings()
    {
        $user = Auth::user();
        $profile = $user->profile;
        $column = $profile?->column;

        $setting = JournalNormativeSetting::firstOrCreate(
            ['user_id' => $user->id],
            ['column' => $column]
        );

        // Load crew for marks table
        $crew = [];
        if ($column) {
            $crew = User::where('role', 'driver')
                ->whereHas('profile', function ($q) use ($column) {
                    $q->where('column', $column);
                })->with('profile')->get();
        }

        return view('journal.settings', compact('setting', 'column', 'crew'));
    }

    public function updateSettings(Request $request)
    {
        $user = Auth::user();
        $profile = $user->profile;

        $validated = $request->validate([
            'kip_linia_bk_months' => 'required|integer|min:1|max:24',
            'kip_linia_3_months' => 'required|integer|min:1|max:24',
            'kip_linia_2_months' => 'required|integer|min:1|max:24',
            'kip_linia_1_months' => 'required|integer|min:1|max:24',
            'kip_manevry_months' => 'required|integer|min:1|max:24',
            'kip_manevry_alternation' => 'boolean',
            'kip_podem_months' => 'required|integer|min:1|max:24',
            'kip_kru_months' => 'required|integer|min:1|max:24',
            'kip_ars_r_months' => 'required|integer|min:1|max:24',
            'kip_pnevmatika_months' => 'required|integer|min:1|max:24',
            'kip_scep_months' => 'required|integer|min:1|max:24',
            'atz_months' => 'required|integer|min:1|max:24',
            'atz_line_months' => 'required|integer|min:1|max:24',
            // crew marks from moved table
            'crew' => 'array',
        ]);

        $settingData = $validated;
        unset($settingData['crew']);
        JournalNormativeSetting::updateOrCreate(
            ['user_id' => $user->id],
            array_merge($settingData, ['column' => $profile?->column])
        );

        // Save crew marks and class if provided (now in settings)
        $crewData = $request->input('crew', []);
        foreach ($crewData as $userId => $data) {
            UserProfile::where('user_id', $userId)->update([
                'normative_class' => $data['class'] ?? null,
                'is_maneuver' => !empty($data['is_maneuver']),
                'is_t6' => !empty($data['is_t6']),
                'is_pomoshnik' => !empty($data['is_pomoshnik']),
            ]);
        }

        return redirect()->route('journal.settings')->with('success', 'Настройки сохранены.');
    }

    // ==================== НОРМАТИВЫ (Сетка) ====================
    public function standards()
    {
        $user = Auth::user();
        $profile = $user->profile;
        $column = $profile?->column;

        if (!$column) {
            return redirect()->route('journal.index')->with('error', 'Колонна не указана.');
        }

        // Crew in the column - only drivers/machinists
        $crew = User::where('role', 'driver')
            ->whereHas('profile', function ($q) use ($column) {
                $q->where('column', $column);
            })->with('profile')->get();

        // Get or create normative records for each
        $setting = JournalNormativeSetting::firstOrCreate(['user_id' => $user->id], ['column' => $column]);

        $types = ['kip_linia', 'kip_manevry', 'kip_podem', 'kip_kru', 'kip_ars_r', 'kip_pnevmatika', 'kip_scep', 'atz', 'atz_line'];

        foreach ($crew as $member) {
            foreach ($types as $type) {
                JournalCrewNormative::firstOrCreate([
                    'user_id' => $member->id,
                    'type' => $type,
                ], [
                    'instructor_id' => $user->id,
                    'column' => $column,
                ]);
            }
        }

        $normatives = JournalCrewNormative::where('instructor_id', $user->id)
            ->where('column', $column)
            ->get()
            ->groupBy('user_id');

        $normativeLabels = [
            'kip_linia' => 'КИП Линия',
            'kip_manevry' => 'КИП Манёвры',
            'kip_podem' => 'КИП Подъём',
            'kip_kru' => 'КИП КРУ',
            'kip_ars_r' => 'КИП АРС-Р',
            'kip_pnevmatika' => 'КИП Пневматика',
            'kip_scep' => 'КИП Сцеп',
            'atz' => 'АТЗ',
            'atz_line' => 'АТЗ на Линии',
        ];

        return view('journal.standards', compact('crew', 'normatives', 'setting', 'column', 'types', 'normativeLabels'));
    }

    public function updateStandards(Request $request)
    {
        $user = Auth::user();
        $profile = $user->profile;
        $column = $profile?->column;

        $validated = $request->validate([
            'normatives' => 'array',
        ]);

        // Update class if provided in norms form
        $crewData = $request->input('crew', []);
        foreach ($crewData as $userId => $data) {
            if (isset($data['class'])) {
                UserProfile::where('user_id', $userId)->update([
                    'normative_class' => $data['class']
                ]);
            }
        }

        // Update normatives and recalc next
        if (!empty($validated['normatives'])) {
            $setting = JournalNormativeSetting::firstOrCreate(['user_id' => $user->id], ['column' => $column]);

            foreach ($validated['normatives'] as $userId => $types) {
                foreach ($types as $type => $dates) {
                    $norm = JournalCrewNormative::where('user_id', $userId)
                        ->where('type', $type)
                        ->first();

                    if ($norm) {
                        $oldLast = $norm->last_date;
                        $norm->last_date = $dates['last_date'] ?? null;
                        $norm->class = UserProfile::where('user_id', $userId)->value('normative_class');
                        $norm->next_date = $this->calculateNextDate($norm->last_date, $type, $norm->class, $setting);
                        if ($type === 'kip_linia') {
                            $norm->start_time = $dates['start_time'] ?? null;
                            $norm->start_station = $dates['start_station'] ?? null;
                            $norm->end_time = $dates['end_time'] ?? null;
                            $norm->end_station = $dates['end_station'] ?? null;
                            $norm->remarks = $dates['remarks'] ?? null;
                            if (isset($dates['occurrences']) && is_array($dates['occurrences'])) {
                                $norm->occurrences = $dates['occurrences'];
                            }
                        } elseif ($type === 'kip_manevry') {
                            $norm->start_time = $dates['start_time'] ?? null;
                            $norm->end_time = $dates['end_time'] ?? null;
                            $norm->details = ['alternation' => $dates['maneuver_alternation'] ?? ''];
                            $norm->remarks = $dates['remarks'] ?? null;
                        } elseif ($type === 'kip_scep') {
                            $norm->details = ['status' => $dates['scep_status'] ?? ''];
                            $norm->remarks = $dates['remarks'] ?? null;
                        } elseif (isset($dates['remarks'])) {
                            $norm->remarks = $dates['remarks'];
                        }
                        $norm->save();

                        // Log directly to ClickHouse (sync to ensure appears in history; crew as user_id)
                        if (!empty($dates['last_date'])) {
                            try {
                                $ch = new \ClickHouseDB\Client(config('clickhouse'));
                                $data = [
                                    'event_date' => date('Y-m-d'),
                                    'event_time' => now()->format('Y-m-d H:i:s'),
                                    'user_id' => (int)$userId, // the crew member
                                    'user_role' => 'driver',
                                    'user_column' => $column,
                                    'action_type' => 'normative_completed',
                                    'resource_id' => 0,
                                    'details' => json_encode([
                                        'type' => $type,
                                        'last_date' => $dates['last_date'],
                                        'next_date' => $norm->next_date ? $norm->next_date->format('Y-m-d') : null,
                                        'remarks' => $norm->remarks ?? null,
                                        'start_time' => $norm->start_time ?? ($dates['start_time'] ?? null),
                                        'end_time' => $norm->end_time ?? ($dates['end_time'] ?? null),
                                        'recorded_by' => Auth::id(),
                                    ], JSON_UNESCAPED_UNICODE),
                                ];
                                $ch->insert('user_actions', [$data], ['event_date', 'event_time', 'user_id', 'user_role', 'user_column', 'action_type', 'resource_id', 'details']);
                            } catch (\Exception $e) {
                                // fail silently for demo
                            }
                        }
                    }
                }
            }
        }

        return redirect()->route('journal.standards')->with('success', 'Нормативы обновлены.');
    }

    private function calculateNextDate($lastDate, $type, $class, $setting)
    {
        if (!$lastDate) return null;

        $last = \Carbon\Carbon::parse($lastDate);

        $months = 12; // default

        if ($type === 'kip_linia') {
            $map = [
                'bk' => $setting->kip_linia_bk_months ?? 4,
                '3' => $setting->kip_linia_3_months ?? 3,
                '2' => $setting->kip_linia_2_months ?? 4,
                '1' => $setting->kip_linia_1_months ?? 4,
            ];
            $months = $map[$class] ?? 4;
        } elseif ($type === 'kip_manevry') {
            $months = $setting->kip_manevry_months ?? 6;
        } elseif ($type === 'kip_podem') {
            $months = $setting->kip_podem_months ?? 12;
        } elseif ($type === 'kip_kru') {
            $months = $setting->kip_kru_months ?? 12;
        } elseif ($type === 'kip_ars_r') {
            $months = $setting->kip_ars_r_months ?? 12;
        } elseif ($type === 'kip_pnevmatika') {
            $months = $setting->kip_pnevmatika_months ?? 12;
        } elseif ($type === 'kip_scep') {
            $months = $setting->kip_scep_months ?? 12;
        } elseif ($type === 'atz') {
            $months = $setting->atz_months ?? 12;
        } elseif ($type === 'atz_line') {
            $months = $setting->atz_line_months ?? 12;
        }

        return $last->copy()->addMonths($months)->format('Y-m-d');
    }

    // ==================== ИСТОРИЯ ====================
    public function history(Request $request)
    {
        $user = Auth::user();
        $profile = $user->profile;
        $column = $profile?->column;

        // Query ClickHouse for history
        $history = [];
        $userMap = [];
        try {
            if ($column) {
                $crews = User::whereHas('profile', fn($q) => $q->where('column', $column))->pluck('name', 'id')->toArray();
                $userMap = $crews;
            }

            $ch = new \ClickHouseDB\Client(config('clickhouse'));
            $query = "
                SELECT 
                    event_time,
                    user_id,
                    action_type,
                    details
                FROM default.user_actions
                WHERE action_type = 'normative_completed'
                ORDER BY event_time DESC
                LIMIT 100
            ";

            $result = $ch->select($query);
            $history = $result->rows();

            // Dedup to avoid duplicate records
            $seen = [];
            $unique = [];
            foreach ($history as $row) {
                $key = ($row['user_id'] ?? '') . '|' . ($row['event_time'] ?? '') . '|' . ($row['details'] ?? '');
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $unique[] = $row;
                }
            }
            $history = $unique;
        } catch (\Exception $e) {
            $history = [];
        }

        $normativeLabels = [
            'kip_linia' => 'КИП Линия',
            'kip_manevry' => 'КИП Манёвры',
            'kip_podem' => 'КИП Подъём',
            'kip_kru' => 'КИП КРУ',
            'kip_ars_r' => 'КИП АРС-Р',
            'kip_pnevmatika' => 'КИП Пневматика',
            'kip_scep' => 'КИП Сцеп',
            'atz' => 'АТЗ',
            'atz_line' => 'АТЗ на Линии',
        ];

        return view('journal.history', compact('history', 'column', 'normativeLabels', 'userMap'));
    }

    public function report(Request $request)
    {
        $user = Auth::user();
        $profile = $user->profile;
        $column = $profile?->column;

        if (!$column) {
            return redirect()->route('journal.index')->with('error', 'Колонна не указана.');
        }

        $crew = User::where('role', 'driver')
            ->whereHas('profile', function ($q) use ($column) {
                $q->where('column', $column);
            })->with('profile')->get();

        $normatives = JournalCrewNormative::where('column', $column)
            ->get()
            ->groupBy('user_id');

        $vacations = JournalVacation::where('column', $column)
            ->orderBy('start_date')
            ->get()
            ->groupBy('user_id');

        $types = ['kip_linia', 'kip_manevry', 'kip_podem', 'kip_kru', 'kip_ars_r', 'kip_pnevmatika', 'kip_scep', 'atz', 'atz_line'];

        $normativeLabels = [
            'kip_linia' => 'КИП Линия',
            'kip_manevry' => 'КИП Манёвры',
            'kip_podem' => 'КИП Подъём',
            'kip_kru' => 'КИП КРУ',
            'kip_ars_r' => 'КИП АРС-Р',
            'kip_pnevmatika' => 'КИП Пневматика',
            'kip_scep' => 'КИП Сцеп',
            'atz' => 'АТЗ',
            'atz_line' => 'АТЗ на Линии',
        ];

        // Compute intersections (vacation overlaps with any next normative date)
        $intersections = [];
        $today = \Carbon\Carbon::today();

        foreach ($crew as $member) {
            $memberNorms = $normatives->get($member->id, collect());
            $memberVacs = $vacations->get($member->id, collect());

            foreach ($memberNorms as $norm) {
                if (!$norm->next_date || $norm->next_date->lt($today)) {
                    continue;
                }

                foreach ($memberVacs as $vac) {
                    if ($norm->next_date->between($vac->start_date, $vac->end_date)) {
                        $intersections[] = [
                            'user_id' => $member->id,
                            'name' => $member->name,
                            'type' => $norm->type,
                            'type_label' => $normativeLabels[$norm->type] ?? $norm->type,
                            'next_date' => $norm->next_date,
                            'vacation_start' => $vac->start_date,
                            'vacation_end' => $vac->end_date,
                            'vacation_type' => $vac->type ?? 'основной',
                        ];
                    }
                }
            }
        }

        // Sort intersections by next_date
        usort($intersections, function($a, $b) {
            return $a['next_date']->timestamp <=> $b['next_date']->timestamp;
        });

        return view('journal.report', compact(
            'column', 'crew', 'normatives', 'vacations', 'types', 'normativeLabels', 'intersections'
        ));
    }

    public function addVacation(Request $request)
    {
        $user = Auth::user();
        $profile = $user->profile;
        $column = $profile?->column;

        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'type' => 'nullable|string|max:50',
            'notes' => 'nullable|string',
        ]);

        JournalVacation::create([
            'user_id' => $validated['user_id'],
            'instructor_id' => $user->id,
            'column' => $column,
            'start_date' => $validated['start_date'],
            'end_date' => $validated['end_date'],
            'type' => $validated['type'] ?? 'основной',
            'notes' => $validated['notes'] ?? null,
        ]);

        return redirect()->route('journal.report')->with('success', 'Период отпуска добавлен.');
    }

    public function deleteVacation($id)
    {
        $user = Auth::user();
        $column = $user->profile?->column;

        $vac = JournalVacation::where(function($q) use ($user, $column) {
                $q->where('instructor_id', $user->id)
                  ->orWhere('column', $column);
            })
            ->findOrFail($id);

        $vac->delete();

        return redirect()->route('journal.report')->with('success', 'Период отпуска удалён.');
    }
}
