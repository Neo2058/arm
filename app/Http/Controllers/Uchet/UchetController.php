<?php

namespace App\Http\Controllers\Uchet;

use App\Http\Controllers\Controller;
use App\Models\ArmAbsence;
use App\Models\ArmAccount;
use App\Models\ArmPeriod;
use App\Models\ArmPersonnel;
use App\Models\NaryadAssignment;
use App\Services\Arm\AccountBuilder;
use App\Services\ClickHouseService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UchetController extends Controller
{
    public function __construct(private AccountBuilder $accounts) {}

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

        return view('uchet.person', [
            'month' => $month,
            'period' => $period,
            'personnel' => $personnel,
            'days' => $days,
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
        $month = $request->validate(['month' => 'required|date_format:Y-m'])['month'];
        if (ArmPeriod::closedMonth($month)) {
            return response()->json(['success' => false, 'message' => 'Месяц закрыт'], 422);
        }
        $result = $this->accounts->buildMonth($month);
        ClickHouseService::log('uchet.accounts.generated', 0, ['month' => $month] + $result);

        return response()->json(['success' => true] + $result);
    }

    public function accounts(Request $request)
    {
        $month = $this->month($request);
        $period = ArmPeriod::query()->firstOrCreate(['year_month' => $month], ['status' => 'open']);
        $accounts = ArmAccount::query()
            ->with(['user.personnel', 'lines'])
            ->where('year_month', $month)
            ->orderBy('id')
            ->get();

        return view('uchet.accounts', [
            'month' => $month,
            'period' => $period,
            'accounts' => $accounts,
            'user' => Auth::user(),
        ]);
    }

    public function close(Request $request)
    {
        $month = $request->validate(['month' => 'required|date_format:Y-m'])['month'];
        $this->accounts->buildMonth($month);
        $period = ArmPeriod::query()->firstOrCreate(['year_month' => $month], ['status' => 'open']);
        $period->update([
            'status' => 'closed',
            'closed_at' => now(),
            'closed_by' => Auth::id(),
        ]);
        ClickHouseService::log('uchet.period.closed', $period->id, ['month' => $month]);

        return response()->json(['success' => true]);
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
        $godmes = str_replace('-', '', $month);
        $lines = ArmAccount::query()
            ->with(['user.personnel', 'lines'])
            ->where('year_month', $month)
            ->get()
            ->flatMap(function (ArmAccount $account) use ($godmes) {
                $tab = $account->user?->personnel?->tab_number
                    ?? sprintf('%04d', $account->user_id);

                return $account->lines->map(fn ($line) => [
                    'godmes' => $godmes,
                    'tabn' => $tab,
                    'vopl' => $line->pay_code,
                    'tarst' => number_format((float) $line->tariff, 3, '.', ''),
                    'procnt' => number_format((float) $line->percent, 2, '.', ''),
                    'vrotr' => number_format((float) $line->hours, 2, '.', ''),
                    'zakaz' => $line->cost_code,
                    'prof' => $line->profession,
                    'nomls' => $line->ls_number,
                    'dde' => '',
                    'osn_prof' => $line->profession,
                    'tabn_old' => $tab,
                    'depo' => $account->user?->personnel?->depo_code,
                    'depokom' => '',
                ]);
            });

        $filename = 'lsbuh_'.$godmes.'.csv';

        return response()->streamDownload(function () use ($lines) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['GODMES', 'TABN', 'VOPL', 'TARST', 'PROCNT', 'VROTR', 'ZAKAZ', 'PROF', 'NOMLS', 'DDE', 'OSN_PROF', 'TABN_OLD', 'DEPO', 'DEPOKOM']);
            foreach ($lines as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function reports(Request $request)
    {
        $month = $this->month($request);
        $start = Carbon::createFromFormat('Y-m', $month)->startOfMonth();
        $end = $start->copy()->endOfMonth();
        $hours = NaryadAssignment::query()
            ->with('user.personnel')
            ->whereBetween('plan_date', [$start->toDateString(), $end->toDateString()])
            ->get()
            ->groupBy('user_id')
            ->map(function ($rows) {
                $user = $rows->first()?->user;

                return [
                    'tab' => $user?->personnel?->tab_number,
                    'name' => $user?->name,
                    'line' => round($rows->sum('hours_line'), 2),
                    'line_2' => round($rows->sum('hours_line_2'), 2),
                    'night' => round($rows->sum(fn ($r) => (float) $r->hours_night + (float) $r->hours_night_2), 2),
                    'evening' => round($rows->sum('hours_evening'), 2),
                    'holiday' => round($rows->sum('hours_holiday'), 2),
                ];
            })->values();

        $absences = ArmAbsence::query()
            ->with('user')
            ->whereDate('starts_on', '<=', $end->toDateString())
            ->whereDate('ends_on', '>=', $start->toDateString())
            ->orderBy('starts_on')
            ->get();

        return view('uchet.reports', [
            'month' => $month,
            'hours' => $hours,
            'absences' => $absences,
            'period' => ArmPeriod::query()->firstOrCreate(['year_month' => $month], ['status' => 'open']),
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
}
