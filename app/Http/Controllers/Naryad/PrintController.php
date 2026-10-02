<?php

namespace App\Http\Controllers\Naryad;

use App\Http\Controllers\Controller;
use App\Services\Arm\NaryadPrintService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Support\Facades\Auth;

class PrintController extends Controller implements HasMiddleware
{
    use EnsuresDispatcher;

    public function __construct(private NaryadPrintService $print) {}

    public function partial(Request $request)
    {
        $this->abortIfNotDispatcher();
        $date = $this->date($request);
        $kind = $this->kind($request);
        $sheet = $this->print->build($date, $kind);

        return view('naryad.partials.print', [
            'sheet' => $sheet,
            'date' => $date->toDateString(),
            'kind' => $kind,
            'user' => Auth::user(),
        ]);
    }

    public function sheet(Request $request)
    {
        $this->abortIfNotDispatcher();
        $date = $this->date($request);
        $kind = $this->kind($request);
        $sheet = $this->print->build($date, $kind);

        return view('naryad.print-sheet', [
            'sheet' => $sheet,
            'date' => $date->toDateString(),
            'kind' => $kind,
            'user' => Auth::user(),
            'autoPrint' => $request->boolean('auto'),
        ]);
    }

    private function date(Request $request): Carbon
    {
        $raw = (string) $request->input('date', '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return Carbon::createFromFormat('Y-m-d', $raw)->startOfDay();
        }
        $month = (string) $request->input('month', '');
        if (preg_match('/^\d{4}-\d{2}$/', $month)) {
            $start = Carbon::createFromFormat('Y-m', $month)->startOfMonth();
            $today = Carbon::now()->startOfDay();
            if ($today->betweenIncluded($start, $start->copy()->endOfMonth())) {
                return $today;
            }

            return $start;
        }

        return Carbon::now()->startOfDay();
    }

    private function kind(Request $request): string
    {
        $kind = (string) $request->input('kind', NaryadPrintService::KIND_FULL);

        return $kind === NaryadPrintService::KIND_EXTRACT
            ? NaryadPrintService::KIND_EXTRACT
            : NaryadPrintService::KIND_FULL;
    }
}
