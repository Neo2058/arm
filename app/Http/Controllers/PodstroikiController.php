<?php

namespace App\Http\Controllers;

use App\Models\Podstroika;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class PodstroikiController extends Controller
{
    public function index()
    {
        $user = Auth::user();
        $roleObj = $user->role;
        $roleValue = is_object($roleObj) && property_exists($roleObj, 'value') ? strtolower($roleObj->value) : strtolower((string) $roleObj);

        $currentMonth = Carbon::now()->startOfMonth();
        $nextMonth = Carbon::now()->addMonth()->startOfMonth();

        $monthFilter = request('month', 'both'); // both, current, next

        if (in_array($roleValue, ['naryadchik', 'dispatcher'])) {
            // Dispatcher sees ONLY the table of applicants for current and next month.
            // Support filter by month. Sorted by tab_number asc.
            // Each row is a separate заявка so status can be changed per month.

            $query = Podstroika::with(['user.profile'])
                ->whereIn('for_month', [$currentMonth, $nextMonth]);

            if ($monthFilter === 'current') {
                $query->where('for_month', $currentMonth);
            } elseif ($monthFilter === 'next') {
                $query->where('for_month', $nextMonth);
            }

            $podstroikas = $query->get()
                ->map(function ($p) {
                    $u = $p->user;
                    $profile = $u->profile;
                    $tab = $profile ? ($profile->tab_number ?? '0000') : '0000';

                    $nameParts = preg_split('/\s+/', trim($u->name));
                    $surname = $nameParts[0] ?? '';
                    $initials = '';
                    if (isset($nameParts[1])) {
                        $initials .= mb_substr($nameParts[1], 0, 1) . '.';
                    }
                    if (isset($nameParts[2])) {
                        $initials .= mb_substr($nameParts[2], 0, 1) . '.';
                    }

                    return [
                        'id' => $p->id,
                        'tab_number' => $tab,
                        'name' => trim($surname . ' ' . $initials),
                        'month' => $p->for_month->format('Y-m'),
                        'details' => $p->details,
                        'status' => $p->status,
                    ];
                })
                ->sortBy(function ($item) {
                    return [(int)$item['tab_number'], $item['month']];
                })
                ->values();

            return view('teaching.podstroiki', [
                'isDispatcher' => true,
                'applicants' => $podstroikas,
                'monthFilter' => $monthFilter,
                'hide_sidebar' => true,
            ]);
        }

        // Regular users
        $myPodstroikas = Podstroika::where('user_id', $user->id)
            ->orderBy('for_month')
            ->get();

        $nextMonthForForm = $nextMonth->format('Y-m-d');

        return view('teaching.podstroiki', [
            'isDispatcher' => false,
            'myPodstroikas' => $myPodstroikas,
            'nextMonth' => $nextMonthForForm,
            'hide_sidebar' => false,
        ]);
    }

    public function store(Request $request)
    {
        $user = Auth::user();
        $roleObj = $user->role;
        $roleValue = is_object($roleObj) && property_exists($roleObj, 'value') ? strtolower($roleObj->value) : strtolower((string) $roleObj);

        if (in_array($roleValue, ['naryadchik', 'dispatcher'])) {
            return redirect()->route('podstroiki.index')->with('error', 'У нарядчиков нет доступа к подаче заявок.');
        }

        $request->validate([
            'for_month' => 'required|date',
            'details' => 'required|string|max:1000',
        ]);

        // Force to next month if not
        $requestedMonth = Carbon::parse($request->for_month)->startOfMonth();
        $nextMonth = Carbon::now()->addMonth()->startOfMonth();

        if (!$requestedMonth->equalTo($nextMonth)) {
            $requestedMonth = $nextMonth;
        }

        Podstroika::updateOrCreate(
            [
                'user_id' => $user->id,
                'for_month' => $requestedMonth,
            ],
            [
                'details' => $request->details,
            ]
        );

        return redirect()->route('podstroiki.index')->with('success', 'Заявка на подстройку смены отправлена.');
    }

    public function updateStatus(Request $request, Podstroika $podstroika)
    {
        $user = Auth::user();
        $roleObj = $user->role;
        $roleValue = is_object($roleObj) && property_exists($roleObj, 'value') ? strtolower($roleObj->value) : strtolower((string) $roleObj);

        if (!in_array($roleValue, ['naryadchik', 'dispatcher'])) {
            abort(403);
        }

        $request->validate([
            'status' => 'required|in:pending,podstroeno',
        ]);

        $podstroika->update(['status' => $request->status]);

        return back()->with('success', 'Статус заявки обновлён. Пользователь увидит изменения.');
    }
}
