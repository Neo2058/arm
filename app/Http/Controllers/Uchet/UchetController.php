<?php

namespace App\Http\Controllers\Uchet;

use App\Http\Controllers\Controller;
use App\Models\ArmAccount;
use App\Models\ArmExtraPay;
use App\Models\ArmMonthPremium;
use App\Models\ArmPeriod;
use App\Models\ArmPersonnel;
use App\Models\ArmUkCopy;
use App\Models\NaryadAssignment;
use App\Services\Arm\AccountBuilder;
use App\Services\Arm\DokmenuReportService;
use App\Services\Arm\LsbuhExportService;
use App\Services\Arm\ShiftHoursService;
use App\Services\Arm\UkCopyService;
use App\Services\ClickHouseService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UchetController extends Controller
{
    public function __construct(
        private AccountBuilder $accounts,
        private UkCopyService $ukCopies,
        private ShiftHoursService $shiftHours,
        private DokmenuReportService $dokmenu,
        private LsbuhExportService $lsbuhExport,
    ) {}

    public function index(Request $request)
    {
        $month = $this->month($request);
        $start = Carbon::createFromFormat('Y-m', $month)->startOfMonth();
        $end = $start->copy()->endOfMonth();
        $period = ArmPeriod::query()->firstOrCreate(
            ['year_month' => $month],
            ['status' => 'open']
        );

        $assignments = NaryadAssignment::query()
            ->with('user.personnel')
            ->whereBetween('plan_date', [$start->toDateString(), $end->toDateString()])
            ->get()
            ->groupBy('user_id');

        $cards = $assignments->map(function ($rows, $userId) {
            $user = $rows->first()?->user;

            return [
                'user_id' => $userId,
                'name' => $user?->name,
                'tab' => $user?->personnel?->tab_number,
                'days' => $rows->count(),
                'hours' => round($rows->sum(fn ($r) => (float) $r->hours_line + (float) $r->hours_reserve), 2),
                'hours_2' => round($rows->sum(fn ($r) => (float) $r->hours_line_2), 2),
                'night' => round($rows->sum(fn ($r) => (float) $r->hours_night + (float) $r->hours_night_2), 2),
            ];
        })->sortBy('name')->values();

        return view('uchet.index', [
            'month' => $month,
            'period' => $period,
            'cards' => $cards,
            'user' => Auth::user(),
        ]);
    }

    public function person(Request $request, int $userId)
    {
        $month = $this->month($request);
        $start = Carbon::createFromFormat('Y-m', $month)->startOfMonth();
        $end = $start->copy()->endOfMonth();
        $period = ArmPeriod::query()->firstOrCreate(['year_month' => $month], ['status' => 'open']);
        $personnel = ArmPersonnel::query()->where('user_id', $userId)->first();
        $days = NaryadAssignment::query()
            ->where('user_id', $userId)
            ->whereBetween('plan_date', [$start->toDateString(), $end->toDateString()])
            ->orderBy('plan_date')
            ->get();
        $extra = $personnel
            ? ArmExtraPay::query()
                ->where('year_month', $month)
                ->where(function ($q) use ($personnel) {
                    $q->where('tab_number', $personnel->tab_number);
                    if ($personnel->user_id) {
                        $q->orWhere('user_id', $personnel->user_id);
                    }
                })
                ->first()
            : null;
        $premium = $personnel
            ? ArmMonthPremium::query()
                ->where('year_month', $month)
                ->where('tab_number', $personnel->tab_number)
                ->orderByRaw('case when position_code = ? then 0 else 1 end', [$personnel->position_code])
                ->first()
            : null;

        return view('uchet.person', [
            'month' => $month,
            'period' => $period,
            'personnel' => $personnel,
            'days' => $days,
            'extra' => $extra,
            'premium' => $premium,
            'user' => Auth::user(),
        ]);
    }

    public function updateHours(Request $request, NaryadAssignment $assignment)
    {
        $month = $assignment->plan_date->format('Y-m');
        if (ArmPeriod::closedMonth($month)) {
            return response()->json(['success' => false, 'message' => 'Месяц закрыт'], 422);
        }
        $data = $request->validate([
            'hours_line' => 'nullable|numeric',
            'hours_line_2' => 'nullable|numeric',
            'hours_night' => 'nullable|numeric',
            'hours_night_2' => 'nullable|numeric',
            'hours_reserve' => 'nullable|numeric',
            'hours_evening' => 'nullable|numeric',
            'hours_break' => 'nullable|numeric',
            'hours_holiday' => 'nullable|numeric',
            'two_person' => 'nullable|boolean',
        ]);
        $assignment->update($data);
        ClickHouseService::log('uchet.hours.updated', $assignment->id, $data);

        return response()->json(['success' => true]);
    }

    public function generate(Request $request)
    {
        $data = $request->validate([
            'month' => 'required|date_format:Y-m',
            'kind' => 'nullable|in:primary,repeat',
        ]);
        $month = $data['month'];
        $kind = $data['kind'] ?? 'primary';
        if (ArmPeriod::closedMonth($month)) {
            return response()->json(['success' => false, 'message' => 'Месяц закрыт'], 422);
        }
        $result = $this->accounts->buildMonth($month, $kind);
        ClickHouseService::log('uchet.accounts.generated', 0, ['month' => $month] + $result);

        return response()->json(['success' => true] + $result);
    }

    public function accounts(Request $request)
    {
        $month = $this->month($request);
        $kind = $request->input('kind', 'primary') === 'repeat' ? 'repeat' : 'primary';
        $period = ArmPeriod::query()->firstOrCreate(['year_month' => $month], ['status' => 'open']);
        $accounts = ArmAccount::query()
            ->with(['user.personnel', 'lines'])
            ->where('year_month', $month)
            ->where('kind', $kind)
            ->orderBy('ls_number')
            ->orderBy('id')
            ->get();

        return view('uchet.accounts', [
            'month' => $month,
            'kind' => $kind,
            'period' => $period,
            'accounts' => $accounts,
            'user' => Auth::user(),
        ]);
    }

    public function close(Request $request)
    {
        $month = $request->validate(['month' => 'required|date_format:Y-m'])['month'];
        $this->accounts->buildMonth($month, 'primary');
        $copied = $this->ukCopies->snapshotMonth($month);
        $period = ArmPeriod::query()->firstOrCreate(['year_month' => $month], ['status' => 'open']);
        $period->update([
            'status' => 'closed',
            'closed_at' => now(),
            'closed_by' => Auth::id(),
        ]);
        ClickHouseService::log('uchet.period.closed', $period->id, ['month' => $month, 'uk_copies' => $copied]);

        return response()->json(['success' => true, 'uk_copies' => $copied]);
    }

    public function reopen(Request $request)
    {
        if (! Auth::user()?->isDispatcher() && ! Auth::user()?->isAdmin()) {
            abort(403);
        }
        $month = $request->validate(['month' => 'required|date_format:Y-m'])['month'];
        $period = ArmPeriod::query()->firstOrCreate(['year_month' => $month], ['status' => 'open']);
        $period->update(['status' => 'open', 'closed_at' => null, 'closed_by' => null]);
        ClickHouseService::log('uchet.period.reopened', $period->id, ['month' => $month]);

        return response()->json(['success' => true]);
    }

    public function lsbuh(Request $request): StreamedResponse
    {
        $month = $this->month($request);
        $kind = $this->lsbuhKind($request);
        $godmes = str_replace('-', '', $month);
        $csv = $this->lsbuhExport->csv($month, $kind);
        ClickHouseService::log('uchet.lsbuh.csv', 0, ['month' => $month, 'kind' => $kind]);

        return response()->streamDownload(function () use ($csv) {
            echo $csv;
        }, 'lsbuh_'.$godmes.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function lsbuhDbf(Request $request): BinaryFileResponse
    {
        $month = $this->month($request);
        $kind = $this->lsbuhKind($request);
        $base = tempnam(sys_get_temp_dir(), 'lsbuh');
        if (is_string($base) && is_file($base)) {
            @unlink($base);
        }
        $path = (is_string($base) ? $base : sys_get_temp_dir().'/lsbuh').'.dbf';
        $this->lsbuhExport->write($path, $month, $kind);
        ClickHouseService::log('uchet.lsbuh.dbf', 0, ['month' => $month, 'kind' => $kind]);

        return response()->download($path, 'LSBUH.DBF', [
            'Content-Type' => 'application/x-dbf',
        ])->deleteFileAfterSend(true);
    }

    public function extras(Request $request)
    {
        $month = $this->month($request);
        $period = ArmPeriod::query()->firstOrCreate(['year_month' => $month], ['status' => 'open']);
        $extras = ArmExtraPay::query()
            ->with('user.personnel')
            ->where('year_month', $month)
            ->orderBy('tab_number')
            ->get();
        $premiums = ArmMonthPremium::query()
            ->with('user.personnel')
            ->where('year_month', $month)
            ->orderBy('tab_number')
            ->get();
        $people = ArmPersonnel::query()->orderBy('full_name')->get(['user_id', 'tab_number', 'full_name', 'position_code']);

        return view('uchet.extras', [
            'month' => $month,
            'period' => $period,
            'extras' => $extras,
            'premiums' => $premiums,
            'people' => $people,
            'user' => Auth::user(),
        ]);
    }

    public function updateExtras(Request $request, int $userId)
    {
        $month = $this->month($request);
        if (ArmPeriod::closedMonth($month)) {
            return response()->json(['success' => false, 'message' => 'Месяц закрыт'], 422);
        }
        $personnel = ArmPersonnel::query()->where('user_id', $userId)->firstOrFail();
        $data = $request->validate([
            'hours_tech' => 'nullable|numeric',
            'tech_on' => 'nullable|date',
            'hours_accident' => 'nullable|numeric',
            'accident_on' => 'nullable|date',
            'hours_med' => 'nullable|numeric',
            'med_on' => 'nullable|date',
            'extra_days_off' => 'nullable|integer|min:0',
            'extra_hours_off' => 'nullable|numeric',
        ]);
        $payload = [
            'user_id' => $personnel->user_id,
            'tab_number' => $personnel->tab_number,
            'full_name' => $personnel->full_name,
            'position_code' => $personnel->position_code,
            'hours_tech' => (float) ($data['hours_tech'] ?? 0),
            'tech_on' => $data['tech_on'] ?? null,
            'hours_accident' => (float) ($data['hours_accident'] ?? 0),
            'accident_on' => $data['accident_on'] ?? null,
            'hours_med' => (float) ($data['hours_med'] ?? 0),
            'med_on' => $data['med_on'] ?? null,
            'extra_days_off' => (int) ($data['extra_days_off'] ?? 0),
            'extra_hours_off' => (float) ($data['extra_hours_off'] ?? 0),
        ];
        ArmExtraPay::updateOrCreate(
            ['year_month' => $month, 'tab_number' => $personnel->tab_number],
            $payload
        );
        ClickHouseService::log('uchet.extras.updated', $personnel->user_id, ['month' => $month] + $payload);

        return response()->json(['success' => true]);
    }

    public function updatePremium(Request $request, int $userId)
    {
        $month = $this->month($request);
        if (ArmPeriod::closedMonth($month)) {
            return response()->json(['success' => false, 'message' => 'Месяц закрыт'], 422);
        }
        $personnel = ArmPersonnel::query()->where('user_id', $userId)->firstOrFail();
        $data = $request->validate([
            'percent_plan' => 'nullable|numeric',
            'percent_fact' => 'nullable|numeric',
            'ktu' => 'nullable|numeric',
            'note' => 'nullable|string|max:40',
        ]);
        $fact = (float) ($data['percent_fact'] ?? $data['percent_plan'] ?? 0);
        $plan = (float) ($data['percent_plan'] ?? $fact);
        ArmMonthPremium::updateOrCreate(
            [
                'year_month' => $month,
                'tab_number' => $personnel->tab_number,
                'position_code' => $personnel->position_code ?: '',
            ],
            [
                'user_id' => $personnel->user_id,
                'percent_plan' => $plan,
                'percent_fact' => $fact,
                'ktu' => (float) ($data['ktu'] ?? 1),
                'note' => $data['note'] ?? null,
            ]
        );
        ClickHouseService::log('uchet.premium.updated', $personnel->user_id, [
            'month' => $month,
            'percent_fact' => $fact,
        ]);

        return response()->json(['success' => true]);
    }

    public function reports(Request $request)
    {
        $month = $this->month($request);
        $from = (string) $request->input('from', Carbon::createFromFormat('Y-m', $month)->startOfMonth()->toDateString());
        $to = (string) $request->input('to', Carbon::createFromFormat('Y-m', $month)->endOfMonth()->toDateString());
        $date = (string) $request->input('date', Carbon::now()->toDateString());
        $people = ArmPersonnel::query()->orderBy('full_name')->get(['user_id', 'tab_number', 'full_name']);

        return view('uchet.reports.index', [
            'month' => $month,
            'from' => $from,
            'to' => $to,
            'date' => $date,
            'from_month' => (string) $request->input('from_month', $month),
            'to_month' => (string) $request->input('to_month', $month),
            'catalog' => $this->dokmenu->catalog(),
            'marks' => $this->dokmenu->availableExtraMarks(),
            'people' => $people,
            'period' => ArmPeriod::query()->firstOrCreate(['year_month' => $month], ['status' => 'open']),
            'user' => Auth::user(),
        ]);
    }

    public function report(Request $request, string $kind)
    {
        $known = collect($this->dokmenu->catalog())->pluck('kind')->all();
        abort_unless(in_array($kind, $known, true), 404);
        $month = $this->month($request);
        $start = Carbon::createFromFormat('Y-m', $month)->startOfMonth();
        $end = $start->copy()->endOfMonth();
        $params = [
            'month' => $month,
            'from_month' => (string) $request->input('from_month', $month),
            'to_month' => (string) $request->input('to_month', $month),
            'from' => (string) $request->input('from', $start->toDateString()),
            'to' => (string) $request->input('to', $end->toDateString()),
            'date' => (string) $request->input('date', Carbon::now()->toDateString()),
            'as_of' => (string) $request->input('as_of', $request->input('date', Carbon::now()->toDateString())),
            'user_id' => (int) $request->input('user_id', 0),
            'mark' => (string) $request->input('mark', ''),
            'extra_mode' => (string) $request->input('extra_mode', 'all'),
            'order' => (string) $request->input('order', 'fio'),
            'ms1' => $request->input('ms1', '1'),
            'ms2' => $request->input('ms2', '1'),
            'ms3' => $request->input('ms3', '1'),
            'ms0' => $request->input('ms0', '1'),
            'pm1' => $request->input('pm1', '1'),
            'pm0' => $request->input('pm0', '1'),
        ];
        $report = $this->dokmenu->build($kind, $params);

        return view('uchet.reports.show', [
            'month' => $month,
            'kind' => $kind,
            'report' => $report,
            'params' => $params,
            'period' => ArmPeriod::query()->firstOrCreate(['year_month' => $month], ['status' => 'open']),
            'user' => Auth::user(),
            'print' => $request->boolean('print'),
        ]);
    }

    public function sheets(Request $request)
    {
        $date = $this->sheetDate($request);
        $month = $date->format('Y-m');
        $period = ArmPeriod::query()->firstOrCreate(['year_month' => $month], ['status' => 'open']);
        $filter = $request->input('filter', 'all');
        $people = ArmPersonnel::query()
            ->with('user')
            ->where(function ($q) use ($date) {
                $q->whereNull('hired_on')->orWhereDate('hired_on', '<=', $date->toDateString());
            })
            ->where(function ($q) use ($date) {
                $q->whereNull('fired_on')->orWhereDate('fired_on', '>=', $date->toDateString());
            })
            ->orderBy('full_name')
            ->get();
        if ($filter === 'mash') {
            $people = $people->filter(fn ($p) => ! str_contains((string) $p->position_code, 'П'));
        } elseif ($filter === 'pom') {
            $people = $people->filter(fn ($p) => str_contains((string) $p->position_code, 'П'));
        }
        $assignments = NaryadAssignment::query()
            ->whereDate('plan_date', $date->toDateString())
            ->get()
            ->keyBy('user_id');
        $rows = $people->map(function (ArmPersonnel $person) use ($assignments) {
            $assignment = $assignments->get($person->user_id);

            return [
                'user_id' => $person->user_id,
                'tab' => $person->tab_number,
                'name' => $person->full_name,
                'position' => $person->position_code,
                'assignment' => $assignment,
            ];
        })->values();

        return view('uchet.sheets', [
            'date' => $date->toDateString(),
            'month' => $month,
            'filter' => $filter,
            'period' => $period,
            'rows' => $rows,
            'user' => Auth::user(),
        ]);
    }

    public function updateSheet(Request $request, int $userId)
    {
        $date = $this->sheetDate($request);
        $month = $date->format('Y-m');
        if (ArmPeriod::closedMonth($month)) {
            return response()->json(['success' => false, 'message' => 'Месяц закрыт'], 422);
        }
        $personnel = ArmPersonnel::query()->where('user_id', $userId)->firstOrFail();
        $data = $request->validate([
            'registered' => 'nullable|boolean',
            'work_code' => 'nullable|string|max:8',
            'shift_code' => 'nullable|string|max:8',
            'hours_line' => 'nullable|numeric',
            'hours_line_2' => 'nullable|numeric',
            'hours_night' => 'nullable|numeric',
        ]);
        $assignment = NaryadAssignment::query()
            ->where('user_id', $userId)
            ->whereDate('plan_date', $date->toDateString())
            ->first();
        $registered = array_key_exists('registered', $data) ? (bool) $data['registered'] : (bool) $assignment?->registered;
        $work = array_key_exists('work_code', $data) ? trim((string) $data['work_code']) : trim((string) ($assignment?->work_code ?? ''));
        $shift = array_key_exists('shift_code', $data) ? trim((string) $data['shift_code']) : trim((string) ($assignment?->shift_code ?? ''));
        $empty = $work === '' && $shift === '' && ! $registered
            && ! filled($data['hours_line'] ?? null)
            && ! filled($data['hours_line_2'] ?? null)
            && ! filled($data['hours_night'] ?? null)
            && ! filled($assignment?->hours_line)
            && ! filled($assignment?->hours_line_2);
        if ($empty) {
            $assignment?->delete();
            ClickHouseService::log('uchet.sheet.cleared', $userId, ['date' => $date->toDateString()]);

            return response()->json(['success' => true, 'deleted' => true]);
        }
        if (! $assignment) {
            $assignment = new NaryadAssignment([
                'user_id' => $userId,
                'plan_date' => $date->toDateString(),
                'route_number' => $work !== '' ? $work : '.',
            ]);
        }
        $assignment->work_code = $work !== '' ? $work : null;
        $assignment->shift_code = $shift !== '' ? $shift : null;
        $assignment->registered = $registered;
        $mark = trim((string) ($assignment->mark_code ?? ''));
        if ($registered && $mark === '') {
            $assignment->mark_code = '+';
        } elseif (! $registered && $mark === '+') {
            $assignment->mark_code = null;
        }
        $route = trim($work.($shift !== '' ? ' '.$shift : ''));
        $assignment->route_number = $route !== '' ? $route : '.';
        if (array_key_exists('hours_line', $data)) {
            $assignment->hours_line = $data['hours_line'];
        }
        if (array_key_exists('hours_line_2', $data)) {
            $assignment->hours_line_2 = $data['hours_line_2'];
        }
        if (array_key_exists('hours_night', $data)) {
            $assignment->hours_night = $data['hours_night'];
        }
        $assignment->save();
        if ($work !== '' && $shift !== '' && ! array_key_exists('hours_line', $data)) {
            $this->shiftHours->applyToAssignment($assignment);
        }
        ClickHouseService::log('uchet.sheet.updated', $assignment->id, [
            'date' => $date->toDateString(),
            'tab' => $personnel->tab_number,
            'registered' => $registered,
        ]);

        return response()->json(['success' => true, 'id' => $assignment->id]);
    }

    public function archive(Request $request)
    {
        $month = $this->month($request);
        $period = ArmPeriod::query()->firstOrCreate(['year_month' => $month], ['status' => 'open']);
        $copies = ArmUkCopy::query()
            ->with('user.personnel')
            ->where('year_month', $month)
            ->get()
            ->groupBy('user_id');
        $cards = $copies->map(function ($rows, $userId) {
            $user = $rows->first()?->user;

            return [
                'user_id' => $userId,
                'name' => $user?->name ?? $user?->personnel?->full_name,
                'tab' => $user?->personnel?->tab_number,
                'days' => $rows->count(),
                'hours' => round($rows->sum(fn ($r) => (float) $r->hours_line + (float) $r->hours_reserve), 2),
                'hours_2' => round($rows->sum(fn ($r) => (float) $r->hours_line_2), 2),
                'night' => round($rows->sum(fn ($r) => (float) $r->hours_night + (float) $r->hours_night_2), 2),
            ];
        })->sortBy('name')->values();

        return view('uchet.archive', [
            'month' => $month,
            'period' => $period,
            'cards' => $cards,
            'user' => Auth::user(),
        ]);
    }

    public function archivePerson(Request $request, int $userId)
    {
        $month = $this->month($request);
        $period = ArmPeriod::query()->firstOrCreate(['year_month' => $month], ['status' => 'open']);
        $personnel = ArmPersonnel::query()->where('user_id', $userId)->first();
        $days = ArmUkCopy::query()
            ->where('user_id', $userId)
            ->where('year_month', $month)
            ->orderBy('plan_date')
            ->get();

        return view('uchet.archive-person', [
            'month' => $month,
            'period' => $period,
            'personnel' => $personnel,
            'days' => $days,
            'user' => Auth::user(),
        ]);
    }

    private function month(Request $request): string
    {
        $month = (string) $request->input('month', Carbon::now()->format('Y-m'));
        if (! preg_match('/^\d{4}-\d{2}$/', $month)) {
            return Carbon::now()->format('Y-m');
        }

        return $month;
    }

    private function lsbuhKind(Request $request): ?string
    {
        $kind = $request->input('kind');

        return in_array($kind, ['primary', 'repeat'], true) ? $kind : null;
    }

    private function sheetDate(Request $request): Carbon
    {
        $raw = (string) $request->input('date', Carbon::now()->toDateString());
        try {
            return Carbon::createFromFormat('Y-m-d', $raw)->startOfDay();
        } catch (\Throwable) {
            return Carbon::now()->startOfDay();
        }
    }
}
