<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Middleware\EnsureInstructor;
use App\Models\JournalCrewNormative;
use App\Models\JournalDocument;
use App\Models\JournalNormativeSetting;
use App\Models\JournalVacation;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\ClickHouseService;
use App\Services\Journal\InstructorTrainingAccounting;
use App\Services\Journal\InstructorTrainingRequests;
use App\Services\Journal\JournalOverview;
use App\Services\Journal\JournalTasks;
use Carbon\Carbon;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TCHMJournalController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            EnsureInstructor::class,
        ];
    }

    public function index(JournalOverview $overview)
    {
        $user = Auth::user();
        $data = $overview->summary($user);
        $column = $data['column'];
        $tasks = $overview->tasks($user)->orderBy('status')->orderBy('due_date')->get();
        $documents = $column === null ? collect() : JournalDocument::where('user_id', $user->id)->where('column', $column)->latest()->get();

        return view('journal.index', [
            'column' => $column ?? 'Не указана', 'tasks' => $tasks, 'documents' => $documents,
            'stats' => $data['stats'], 'error' => $data['error'],
        ]);
    }

    private function mutateTask(Request $request, string $action, ?int $id, JournalTasks $service, string $message)
    {
        try {
            $service->mutate(Auth::user(), $action, $id, $request->all(), false);
        } catch (HttpResponseException $e) {
            if ($e->getResponse()->getStatusCode() !== 412) {
                throw $e;
            }

            return redirect()->route('journal.index')->with('error', 'Задача изменена на другом устройстве. Проверьте актуальную запись.');
        }

        return redirect()->route('journal.index')->with('success', $message);
    }

    public function addTodo(Request $request, JournalTasks $service)
    {
        return $this->mutateTask($request, 'create', null, $service, 'Задача добавлена в журнал.');
    }

    public function completeTodo(Request $request, $id, JournalTasks $service)
    {
        return $this->mutateTask($request, 'complete', (int) $id, $service, 'Задача отмечена выполненной.');
    }

    public function updateTodo(Request $request, $id, JournalTasks $service)
    {
        return $this->mutateTask($request, 'update', (int) $id, $service, 'Задача обновлена.');
    }

    public function deleteTodo(Request $request, $id, JournalTasks $service)
    {
        return $this->mutateTask($request, 'delete', (int) $id, $service, 'Задача удалена.');
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
        $path = $file->store('journal-documents/'.$user->id, 'local'); // or 's3'

        $doc = JournalDocument::create([
            'user_id' => $user->id,
            'column' => $profile?->column,
            'title' => $validated['title'],
            'file_path' => $path,
            'extracted_text' => null,
        ]);
        ClickHouseService::log('journal.document.uploaded', $doc->id, ['title' => $doc->title]);

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

        $answer = $this->answerFromDocument($validated['question'], $doc);

        // Store last Q&A in session for immediate add to todo
        session(['last_qa' => [
            'question' => $validated['question'],
            'answer' => $answer,
            'document_title' => $doc->title,
        ]]);

        ClickHouseService::log('journal.document.asked', $doc->id, [
            'title' => $doc->title,
        ]);

        return redirect()->route('journal.index')->with('qa_result', [
            'question' => $validated['question'],
            'answer' => $answer,
            'doc_id' => $doc->id,
        ]);
    }

    private function answerFromDocument(string $question, JournalDocument $doc): string
    {
        $text = trim((string) $doc->extracted_text);
        if ($text === '') {
            return 'Текст PDF не извлечён. Добавьте задачу в TODO вручную.';
        }

        $words = preg_split('/\s+/u', mb_strtolower($question)) ?: [];
        $needles = array_values(array_filter($words, fn (string $word) => mb_strlen($word) >= 4));

        if ($needles === []) {
            return mb_substr($text, 0, 400);
        }

        $haystack = mb_strtolower($text);
        $matches = [];
        foreach ($needles as $needle) {
            $pos = mb_strpos($haystack, $needle);
            if ($pos === false) {
                continue;
            }
            $start = max(0, $pos - 80);
            $matches[] = trim(mb_substr($text, $start, 240));
        }

        if ($matches === []) {
            return 'В извлечённом тексте документа нет совпадений с запросом.';
        }

        return implode("\n…\n", array_unique($matches));
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
            $crew = User::where('role', UserRole::DRIVER)
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
        DB::transaction(function () use ($user, $settingData) {
            // Serialize the legacy settings form with ATZ completion and dedicated periods form.
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            app(InstructorTrainingAccounting::class)->periods($user, [
                'operation_id' => (string) Str::uuid(),
                'atz_months' => $settingData['atz_months'],
                'atz_line_months' => $settingData['atz_line_months'],
            ]);
            JournalNormativeSetting::updateOrCreate(
                ['user_id' => $user->id],
                array_merge($settingData, ['column' => app(InstructorTrainingRequests::class)->column($user->fresh())])
            );
        });

        // Save crew marks and class if provided (now in settings)
        $crewData = $request->input('crew', []);
        foreach ($crewData as $userId => $data) {
            UserProfile::where('user_id', $userId)->update([
                'normative_class' => $data['class'] ?? null,
                'is_maneuver' => ! empty($data['is_maneuver']),
                'is_t6' => ! empty($data['is_t6']),
                'is_pomoshnik' => ! empty($data['is_pomoshnik']),
            ]);
        }

        ClickHouseService::log('journal.settings.updated', $user->id, [
            'column' => $profile?->column,
        ]);

        return redirect()->route('journal.settings')->with('success', 'Настройки сохранены.');
    }

    // ==================== НОРМАТИВЫ (Сетка) ====================
    public function standards()
    {
        $user = Auth::user();
        $profile = $user->profile;
        $column = $profile?->column;

        if (! $column) {
            return redirect()->route('journal.index')->with('error', 'Колонна не указана.');
        }

        // Crew in the column - only drivers/machinists
        $crew = User::where('role', UserRole::DRIVER)
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
        return DB::transaction(function () use ($request) {
            $user = Auth::user()->fresh();
            User::whereKey($user->id)->lock(DB::getDriverName() === 'pgsql' ? 'for no key update' : true)->firstOrFail();
            UserProfile::where('user_id', $user->id)->lockForUpdate()->first();
            app(InstructorTrainingRequests::class)->column($user);
            $profile = $user->profile;
            $column = $profile?->column;

            $validated = $request->validate([
                'normatives' => 'array',
            ]);

            // Update class if provided in norms form
            $crewData = $request->input('crew', []);
            foreach ($crewData as $userId => $data) {
                if (isset($data['class'])) {
                    UserProfile::where('user_id', $userId)->where('column', $column)->update([
                        'normative_class' => $data['class'],
                    ]);
                }
            }

            // Update normatives and recalc next
            if (! empty($validated['normatives'])) {
                $setting = JournalNormativeSetting::firstOrCreate(['user_id' => $user->id], ['column' => $column]);

                foreach ($validated['normatives'] as $userId => $types) {
                    foreach ($types as $type => $dates) {
                        $norm = JournalCrewNormative::where('user_id', $userId)
                            ->where('instructor_id', $user->id)
                            ->where('column', $column)
                            ->where('type', $type)
                            ->first();

                        if ($norm) {
                            $oldLast = $norm->last_date;
                            if (in_array($type, ['atz', 'atz_line'], true) && ($oldLast?->toDateString() ?? '') !== ($dates['last_date'] ?? '')) {
                                throw ValidationException::withMessages(['normatives' => 'Выполнение АТЗ отмечается через карточку занятия с выбором присутствовавших.']);
                            }
                            if (in_array($type, ['atz', 'atz_line'], true)) {
                                continue; // ATZ dates are maintained by attendance accounting and period settings.
                            }
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

                            if (! empty($dates['last_date'])) {
                                ClickHouseService::log('normative_completed', 0, [
                                    'type' => $type,
                                    'last_date' => $dates['last_date'],
                                    'next_date' => $norm->next_date ? $norm->next_date->format('Y-m-d') : null,
                                    'remarks' => $norm->remarks ?? null,
                                    'start_time' => $norm->start_time ?? ($dates['start_time'] ?? null),
                                    'end_time' => $norm->end_time ?? ($dates['end_time'] ?? null),
                                    'recorded_by' => Auth::id(),
                                    'column' => $column,
                                ], (int) $userId);
                            }
                        }
                    }
                }
            }

            ClickHouseService::log('journal.standards.updated', $user->id, [
                'column' => $column,
            ]);

            return redirect()->route('journal.standards')->with('success', 'Нормативы обновлены.');
        }, 3);
    }

    private function calculateNextDate($lastDate, $type, $class, $setting)
    {
        if (! $lastDate) {
            return null;
        }

        $last = Carbon::parse($lastDate);

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

        if (in_array($type, ['atz', 'atz_line'], true)) {
            return InstructorTrainingAccounting::nextDate($last->toDateString(), (int) $months);
        }

        return $last->copy()->addMonths($months)->format('Y-m-d');
    }

    // ==================== ИСТОРИЯ ====================
    public function history(Request $request)
    {
        $user = Auth::user();
        $profile = $user->profile;
        $column = $profile?->column;

        $history = [];
        $userMap = [];
        if ($column) {
            $userMap = User::whereHas('profile', fn ($q) => $q->where('column', $column))->pluck('name', 'id')->toArray();
            $quotedColumn = ClickHouseService::quote($column);
            $rows = ClickHouseService::select("
                SELECT
                    event_time,
                    user_id,
                    action_type,
                    details
                FROM default.user_actions
                WHERE action_type = 'normative_completed'
                  AND user_column = {$quotedColumn}
                ORDER BY event_time DESC
                LIMIT 100
            ");

            $seen = [];
            foreach ($rows as $row) {
                $key = ($row['user_id'] ?? '').'|'.($row['event_time'] ?? '').'|'.($row['details'] ?? '');
                if (! isset($seen[$key])) {
                    $seen[$key] = true;
                    $history[] = $row;
                }
            }
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

        if (! $column) {
            return redirect()->route('journal.index')->with('error', 'Колонна не указана.');
        }

        $crew = User::where('role', UserRole::DRIVER)
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
        $today = Carbon::today();

        foreach ($crew as $member) {
            $memberNorms = $normatives->get($member->id, collect());
            $memberVacs = $vacations->get($member->id, collect());

            foreach ($memberNorms as $norm) {
                if (! $norm->next_date || $norm->next_date->lt($today)) {
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
        usort($intersections, function ($a, $b) {
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

        $vacation = JournalVacation::create([
            'user_id' => $validated['user_id'],
            'instructor_id' => $user->id,
            'column' => $column,
            'start_date' => $validated['start_date'],
            'end_date' => $validated['end_date'],
            'type' => $validated['type'] ?? 'основной',
            'notes' => $validated['notes'] ?? null,
        ]);
        ClickHouseService::log('journal.vacation.created', $vacation->id, [
            'target_user_id' => $vacation->user_id,
            'start_date' => $validated['start_date'],
            'end_date' => $validated['end_date'],
        ]);

        return redirect()->route('journal.report')->with('success', 'Период отпуска добавлен.');
    }

    public function deleteVacation($id)
    {
        $user = Auth::user();
        $column = $user->profile?->column;

        $vac = JournalVacation::where(function ($q) use ($user, $column) {
            $q->where('instructor_id', $user->id)
                ->orWhere('column', $column);
        })
            ->findOrFail($id);

        $vacId = $vac->id;
        $target = $vac->user_id;
        $vac->delete();
        ClickHouseService::log('journal.vacation.deleted', $vacId, [
            'target_user_id' => $target,
        ]);

        return redirect()->route('journal.report')->with('success', 'Период отпуска удалён.');
    }
}
