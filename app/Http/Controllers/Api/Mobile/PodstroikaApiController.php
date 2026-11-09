<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Podstroika;
use App\Models\User;
use App\Services\PodstroikaService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PodstroikaApiController extends Controller
{
    /**
     * Список заявок текущего пользователя с фильтрацией по месяцу и статусом лимита.
     * Читает из канонической таблицы `podstroikas`.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $tabNumber = PodstroikaService::resolveTabNumber($user);

        // Расчет месяца без перескока 31-го числа
        $month = $request->query('month', Carbon::now()->startOfMonth()->addMonthsNoOverflow(1)->format('Y-m'));
        $startOfMonth = Carbon::parse($month . '-01')->startOfMonth();
        $startDate = $startOfMonth->format('Y-m-d');
        $endDate = $startOfMonth->copy()->endOfMonth()->format('Y-m-d');

        $limitStatus = PodstroikaService::getLimitStatus($tabNumber, $user->id, $month);

        $items = [];

        if (Schema::hasTable('podstroikas')) {
            $myPodstroikas = Podstroika::where('user_id', $user->id)
                ->where(function ($q) use ($startDate, $endDate) {
                    $q->whereBetween('target_date', [$startDate, $endDate])
                      ->orWhere('for_month', $startDate);
                })
                ->orderBy('target_date', 'asc')
                ->get();

            foreach ($myPodstroikas as $p) {
                $statusUpper = strtoupper($p->status);
                $statusLabel = match ($statusUpper) {
                    'APPLIED', 'PODSTROENO' => 'Подстроено в наряд',
                    'REJECTED' => 'Отклонено',
                    'CANCELLED' => 'Отозвано',
                    default => 'В ожидании',
                };

                $items[] = [
                    'id' => (int)$p->id,
                    'tab_number' => $tabNumber,
                    'target_date' => $p->target_date ? $p->target_date->format('Y-m-d') : ($p->for_month ? $p->for_month->format('Y-m-d') : null),
                    'for_month' => $p->for_month ? $p->for_month->format('Y-m') : Carbon::parse($p->target_date)->format('Y-m'),
                    'route_code' => $p->route_code ?: '',
                    'shift_code' => $p->shift_code ?: '',
                    'details' => $p->details,
                    'status' => strtolower($p->status),
                    'status_label' => $statusLabel,
                    'is_applied' => in_array($statusUpper, ['APPLIED', 'PODSTROENO']),
                    'can_cancel' => !in_array($statusUpper, ['APPLIED', 'PODSTROENO', 'CANCELLED', 'REJECTED']),
                    'created_at' => $p->created_at?->toIso8601String(),
                ];
            }
        } elseif (Schema::hasTable('arm_podstroiki')) {
            $rows = DB::table('arm_podstroiki')
                ->where('tab_number', $tabNumber)
                ->whereBetween('target_date', [$startDate, $endDate])
                ->orderBy('target_date', 'asc')
                ->get();

            foreach ($rows as $r) {
                $statusUpper = strtoupper($r->status);
                $statusLabel = match ($statusUpper) {
                    'APPLIED', 'PODSTROENO' => 'Подстроено в наряд',
                    'REJECTED' => 'Отклонено',
                    'CANCELLED' => 'Отозвано',
                    default => 'В ожидании',
                };

                $items[] = [
                    'id' => (int)$r->id,
                    'tab_number' => $r->tab_number,
                    'target_date' => $r->target_date,
                    'for_month' => Carbon::parse($r->target_date)->format('Y-m'),
                    'route_code' => $r->route_code,
                    'shift_code' => $r->shift_code,
                    'details' => $r->details,
                    'status' => strtolower($statusUpper),
                    'status_label' => $statusLabel,
                    'is_applied' => in_array($statusUpper, ['APPLIED', 'PODSTROENO']),
                    'can_cancel' => !in_array($statusUpper, ['APPLIED', 'PODSTROENO', 'CANCELLED', 'REJECTED']),
                    'created_at' => $r->created_at,
                ];
            }
        }

        return response()->json([
            'success' => true,
            'month' => $month,
            'tab_number' => $tabNumber,
            'user_name' => $user->name,
            'limit' => $limitStatus,
            'podstroikas' => $items,
        ]);
    }

    /**
     * Подача новой заявки из мобильного приложения с обязательным operation_id для идемпотентности.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->isDispatcher()) {
            return response()->json([
                'success' => false,
                'message' => 'Нарядчики не могут подавать заявки на подстройку для себя.',
            ], 403);
        }

        $validated = $request->validate([
            'target_date' => 'required|date',
            'route_code' => 'required|string|max:20',
            'shift_code' => 'required|string|max:10',
            'details' => 'nullable|string|max:1000',
            'operation_id' => 'required|uuid',
        ]);

        try {
            $res = PodstroikaService::createPodstroika(
                $user,
                $validated['target_date'],
                $validated['route_code'],
                $validated['shift_code'],
                $validated['details'] ?? '',
                'mobile',
                null,
                $validated['operation_id']
            );
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $e->getStatusCode());
        }

        if (!$res['success']) {
            return response()->json([
                'success' => false,
                'message' => $res['message'],
            ], 422);
        }

        return response()->json($res, 201);
    }

    /**
     * Получить информацию о лимитах для текущего пользователя.
     */
    public function limits(Request $request): JsonResponse
    {
        $user = $request->user();
        $tabNumber = PodstroikaService::resolveTabNumber($user);

        $curYm = Carbon::now()->format('Y-m');
        $nextYm = Carbon::now()->startOfMonth()->addMonthsNoOverflow(1)->format('Y-m');

        return response()->json([
            'success' => true,
            'tab_number' => $tabNumber,
            'current_month' => PodstroikaService::getLimitStatus($tabNumber, $user->id, $curYm),
            'next_month' => PodstroikaService::getLimitStatus($tabNumber, $user->id, $nextYm),
        ]);
    }

    /**
     * Отмена (отзыв) своей заявки.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $res = PodstroikaService::cancelPodstroika($id, $request->user());
        return response()->json($res, $res['success'] ? 200 : 422);
    }

    /**
     * Защищенный эндпоинт синхронизации для диспетчера АРМ Наряд.
     * Требует полномочий нарядчика (isDispatcher / admin).
     * Передает версии карточек АТЗ, server_id, статус и полные составы.
     */
    public function syncForNaryad(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user->isDispatcher() && !$user->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Доступ запрещён. Требуются права нарядчика.'], 403);
        }

        $month = $request->query('month', Carbon::now()->startOfMonth()->addMonthsNoOverflow(1)->format('Y-m'));
        $startOfMonth = Carbon::parse($month . '-01')->startOfMonth();
        $startDate = $startOfMonth->format('Y-m-d');
        $endDate = $startOfMonth->copy()->endOfMonth()->format('Y-m-d');

        // 1. Индивидуальные подстройки машинистов
        $podstroikas = Podstroika::where(function ($q) use ($startDate, $endDate) {
                $q->whereBetween('target_date', [$startDate, $endDate])
                  ->orWhere('for_month', $startDate);
            })
            ->with(['user.profile'])
            ->orderBy('id', 'asc')
            ->get()
            ->map(function ($p) {
                return [
                    'id' => $p->id,
                    'server_id' => $p->id,
                    'tab_number' => $p->user?->profile?->tab_number ?? '',
                    'driver_name' => $p->user?->name ?? '',
                    'target_date' => $p->target_date ? $p->target_date->format('Y-m-d') : null,
                    'route_code' => $p->route_code,
                    'shift_code' => $p->shift_code,
                    'details' => $p->details,
                    'status' => strtoupper($p->status),
                    'source' => $p->source ?? 'mobile',
                ];
            });

        // 2. Групповые карточки АТЗ инструкторов (с версиями, статусом и участниками)
        $instructorCards = collect();
        if (Schema::hasTable('instructor_training_requests')) {
            $trainingCards = DB::table('instructor_training_requests')
                ->where('planning_month', $startDate)
                ->orderBy('id', 'asc')
                ->get();

            $completedIds = collect();
            if (Schema::hasTable('instructor_training_completions')) {
                $completedIds = DB::table('instructor_training_completions')
                    ->whereIn('request_id', $trainingCards->pluck('id'))
                    ->pluck('request_id')
                    ->flip();
            }

            $members = collect();
            if (Schema::hasTable('instructor_training_members') && $trainingCards->isNotEmpty()) {
                $q = DB::table('instructor_training_members')
                    ->join('users', 'users.id', '=', 'instructor_training_members.user_id')
                    ->leftJoin('user_profiles', 'user_profiles.user_id', '=', 'users.id')
                    ->whereIn('request_id', $trainingCards->pluck('id'));

                if (Schema::hasTable('arm_personnel')) {
                    $q->leftJoin('arm_personnel', 'arm_personnel.user_id', '=', 'users.id')
                      ->select(
                          'request_id',
                          'users.id as user_id',
                          'users.name',
                          DB::raw('COALESCE(NULLIF(user_profiles.tab_number, \'\'), arm_personnel.tab_number, \'\') as tab_number')
                      );
                } else {
                    $q->select('request_id', 'users.id as user_id', 'users.name', 'user_profiles.tab_number');
                }

                $members = $q->get()->groupBy('request_id');
            }

            $instructorCards = $trainingCards->map(function ($tc) use ($members, $completedIds) {
                $instructor = User::find($tc->instructor_id);
                $isCompleted = isset($completedIds[$tc->id]);
                $instTab = $instructor?->profile?->tab_number ?? '';
                if (empty($instTab) && $instructor && Schema::hasTable('arm_personnel')) {
                    $instTab = DB::table('arm_personnel')->where('user_id', $instructor->id)->value('tab_number') ?? '';
                }

                return [
                    'id' => $tc->id,
                    'server_id' => $tc->id,
                    'instructor_id' => $tc->instructor_id,
                    'instructor_name' => $instructor?->name ?? 'Инструктор',
                    'instructor_tab' => $instTab,
                    'column' => $tc->column,
                    'planning_month' => substr($tc->planning_month, 0, 7),
                    'shift_type' => $tc->shift_type,
                    'participant_count' => (int)$tc->participant_count,
                    'version' => (int)($tc->version ?? 1),
                    'status' => $isCompleted ? 'COMPLETED' : 'PENDING',
                    'participants' => $members->get($tc->id, collect())->map(fn ($m) => [
                        'id' => $m->user_id,
                        'tab_number' => $m->tab_number ?? '',
                        'name' => $m->name,
                    ])->values()->all(),
                ];
            });
        }

        return response()->json([
            'success' => true,
            'month' => $month,
            'podstroikas' => $podstroikas,
            'instructor_training_cards' => $instructorCards,
        ]);
    }
}
