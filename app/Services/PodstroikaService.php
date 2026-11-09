<?php

namespace App\Services;

use App\Models\NaryadPodstroikaLimit;
use App\Models\Podstroika;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class PodstroikaService
{
    /**
     * Получить подтверждённый табельный номер пользователя.
     * Запрещена подстановка синтетических номеров во избежание коллизий.
     *
     * @throws ValidationException
     */
    public static function resolveTabNumber(User $user, ?string $explicitTab = null): string
    {
        if (!empty($explicitTab) && ($user->isDispatcher() || $user->isAdmin())) {
            return trim($explicitTab);
        }

        // Проверяем UserProfile
        if ($user->profile && !empty($user->profile->tab_number)) {
            return trim($user->profile->tab_number);
        }

        // Проверяем arm_personnel
        try {
            if (Schema::hasTable('arm_personnel')) {
                $tab = DB::table('arm_personnel')->where('user_id', $user->id)->value('tab_number');
                if (!empty($tab)) {
                    return trim($tab);
                }
            }
        } catch (\Throwable $e) {}

        throw ValidationException::withMessages([
            'tab_number' => 'Табельный номер сотрудника не привязан к учётной записи. Обратитесь к администратору депо.',
        ]);
    }

    /**
     * Получить статус лимита подстроек для сотрудника на указанный месяц.
     * Канонический источник данных: таблица `podstroikas`.
     */
    public static function getLimitStatus(string $tabNumber, ?int $userId = null, ?string $yearMonth = null): array
    {
        // Календарная арифметика без перескока 31-го числа
        $ym = $yearMonth ?: Carbon::now()->startOfMonth()->addMonthsNoOverflow(1)->format('Y-m');
        $startOfMonth = Carbon::parse($ym . '-01')->startOfMonth();
        $startDate = $startOfMonth->format('Y-m-d');
        $endDate = $startOfMonth->copy()->endOfMonth()->format('Y-m-d');

        // Определяем userId, если не был передан явно
        if (!$userId) {
            try {
                if (Schema::hasTable('user_profiles')) {
                    $userId = DB::table('user_profiles')->where('tab_number', $tabNumber)->value('user_id');
                }
            } catch (\Throwable $e) {}
        }
        if (!$userId) {
            try {
                if (Schema::hasTable('arm_personnel')) {
                    $userId = DB::table('arm_personnel')->where('tab_number', $tabNumber)->value('user_id');
                }
            } catch (\Throwable $e) {}
        }

        $maxApproved = 1; // Лимит по умолчанию

        // 1. Проверяем arm_podstroika_limits или NaryadPodstroikaLimit
        $limitFound = false;
        try {
            if (Schema::hasTable('arm_podstroika_limits')) {
                $row = DB::table('arm_podstroika_limits')
                    ->where('tab_number', $tabNumber)
                    ->where('for_month', $ym)
                    ->first();

                if ($row && isset($row->max_approved)) {
                    $maxApproved = (int)$row->max_approved;
                    $limitFound = true;
                }
            }
        } catch (\Throwable $e) {}

        if (!$limitFound && $userId) {
            try {
                $row = NaryadPodstroikaLimit::where('user_id', $userId)
                    ->where('for_month', $startOfMonth)
                    ->first();
                if ($row) {
                    $maxApproved = (int)$row->max_approved;
                }
            } catch (\Throwable $e2) {}
        }

        // 2. Считаем активные заявки за этот месяц (канонический источник podstroikas)
        $usedCount = 0;
        if ($userId && Schema::hasTable('podstroikas')) {
            $usedCount = DB::table('podstroikas')
                ->where('user_id', $userId)
                ->where(function ($q) use ($startDate, $endDate) {
                    $q->whereBetween('target_date', [$startDate, $endDate])
                      ->orWhere('for_month', $startDate);
                })
                ->whereIn('status', ['pending', 'podstroeno', 'PENDING', 'APPLIED'])
                ->count();
        } elseif (Schema::hasTable('arm_podstroiki')) {
            // Фолбэк на arm_podstroiki только при отсутствии пользователя в веб-системе
            $usedCount = DB::table('arm_podstroiki')
                ->where('tab_number', $tabNumber)
                ->whereBetween('target_date', [$startDate, $endDate])
                ->whereIn('status', ['PENDING', 'APPLIED', 'pending', 'podstroeno'])
                ->count();
        }

        $remaining = max(0, $maxApproved - $usedCount);

        return [
            'tab_number' => $tabNumber,
            'month' => $ym,
            'month_label' => Carbon::parse($ym . '-01')->translatedFormat('F Y'),
            'max_approved' => $maxApproved,
            'used' => $usedCount,
            'remaining' => $remaining,
            'can_submit' => ($usedCount < $maxApproved),
        ];
    }

    /**
     * Валидация условий перед подачей заявки на подстройку смены.
     */
    public static function validateConditions(
        string $tabNumber,
        string $targetDate,
        string $routeCode,
        string $shiftCode,
        ?int $userId = null
    ): array {
        $dt = Carbon::parse($targetDate);
        $today = Carbon::today();

        // 1. Проверка даты (не в прошлом)
        if ($dt->lt($today)) {
            return [false, 'Нельзя подать заявку на прошедшую дату.', null];
        }

        // 2. Месяц заявки (текущий или последующие 2 месяца)
        $currentMonth = Carbon::now()->startOfMonth();
        $maxAllowedMonth = Carbon::now()->startOfMonth()->addMonthsNoOverflow(2);
        $reqMonth = $dt->copy()->startOfMonth();

        if ($reqMonth->lt($currentMonth) || $reqMonth->gt($maxAllowedMonth)) {
            return [false, 'Заявки принимаются только на текущий или последующие 2 месяца.', null];
        }

        $ym = $dt->format('Y-m');

        // 3. Проверка месячного лимита
        $limitStatus = self::getLimitStatus($tabNumber, $userId, $ym);
        if (!$limitStatus['can_submit']) {
            return [
                false,
                "Превышен лимит подстроек на {$limitStatus['month_label']} (максимум: {$limitStatus['max_approved']}, уже подано: {$limitStatus['used']}).",
                null
            ];
        }

        // 4. Проверка уникальности заявки на эту дату по каноническому источнику
        if ($userId && Schema::hasTable('podstroikas')) {
            $existsInCanonical = DB::table('podstroikas')
                ->where('user_id', $userId)
                ->where('target_date', $dt->format('Y-m-d'))
                ->whereIn('status', ['pending', 'podstroeno', 'PENDING', 'APPLIED'])
                ->exists();

            if ($existsInCanonical) {
                return [false, "На дату {$dt->format('d.m.Y')} уже подана активная заявка на подстройку.", null];
            }
        } elseif (Schema::hasTable('arm_podstroiki')) {
            $existsInArm = DB::table('arm_podstroiki')
                ->where('tab_number', $tabNumber)
                ->where('target_date', $dt->format('Y-m-d'))
                ->whereIn('status', ['PENDING', 'APPLIED', 'pending', 'podstroeno'])
                ->exists();

            if ($existsInArm) {
                return [false, "На дату {$dt->format('d.m.Y')} уже подана активная заявка на подстройку.", null];
            }
        }

        // 5. Проверка режима труда и отдыха
        $intervalWarning = self::checkRestIntervals($tabNumber, $dt, $shiftCode, $routeCode);

        return [true, null, $intervalWarning];
    }

    /**
     * Проверка междусменного отдыха относительно существующих смен.
     */
    public static function checkRestIntervals(string $tabNumber, Carbon $targetDate, string $shiftCode, string $routeCode): ?array
    {
        $prevDate = $targetDate->copy()->subDay()->format('Y-m-d');
        $nextDate = $targetDate->copy()->addDay()->format('Y-m-d');

        [$curStartMin, $curEndMin] = self::estimateShiftTimes($shiftCode);

        // 1. Проверяем предыдущий день
        try {
            if (Schema::hasTable('arm_naryad_assignments')) {
                $prevAssign = DB::table('arm_naryad_assignments')
                    ->where('tab_number', $tabNumber)
                    ->where('assignment_date', $prevDate)
                    ->first();

                if ($prevAssign && !empty($prevAssign->shift_code) && $prevAssign->shift_code !== 'В') {
                    [, $prevEndMin] = self::estimateShiftTimes($prevAssign->shift_code);
                    $restMinutes = (1440 - $prevEndMin) + $curStartMin;
                    $restHours = round($restMinutes / 60.0, 1);

                    if ($restHours < 12.0) {
                        return [
                            'type' => 'INTERVAL_WARNING',
                            'message' => "Внимание: междусменный отдых после смены {$prevDate} (см. {$prevAssign->shift_code}) составит {$restHours} ч. (норма не менее 12 ч). Нарядчику потребуется подтверждение.",
                            'rest_hours' => $restHours,
                            'required_hours' => 12.0,
                        ];
                    }
                }
            }
        } catch (\Throwable $e) {}

        // 2. Проверяем следующий день
        try {
            if (Schema::hasTable('arm_naryad_assignments')) {
                $nextAssign = DB::table('arm_naryad_assignments')
                    ->where('tab_number', $tabNumber)
                    ->where('assignment_date', $nextDate)
                    ->first();

                if ($nextAssign && !empty($nextAssign->shift_code) && $nextAssign->shift_code !== 'В') {
                    [$nextStartMin, ] = self::estimateShiftTimes($nextAssign->shift_code);
                    $restMinutes = (1440 - $curEndMin) + $nextStartMin;
                    $restHours = round($restMinutes / 60.0, 1);

                    if ($restHours < 12.0) {
                        return [
                            'type' => 'INTERVAL_WARNING',
                            'message' => "Внимание: междусменный отдых перед сменой {$nextDate} (см. {$nextAssign->shift_code}) составит {$restHours} ч. (норма не менее 12 ч).",
                            'rest_hours' => $restHours,
                            'required_hours' => 12.0,
                        ];
                    }
                }
            }
        } catch (\Throwable $e) {}

        return null;
    }

    /**
     * Оценка времени начала и окончания смены в минутах от 00:00.
     */
    public static function estimateShiftTimes(string $shiftCode): array
    {
        $code = trim($shiftCode);
        if ($code === '1' || str_starts_with($code, '1')) {
            return [390, 870]; // 06:30 - 14:30
        } elseif ($code === '2' || str_starts_with($code, '2')) {
            return [840, 1350]; // 14:00 - 22:30
        } elseif ($code === '3' || str_starts_with($code, '3')) {
            return [1320, 390]; // 22:00 - 06:30 (+1 день)
        }
        return [480, 1020];
    }

    /**
     * Создать заявку на подстройку смены с блокировкой пользователя, идемпотентностью по UUID-квитанции
     * и согласованной атомарной транзакцией.
     */
    public static function createPodstroika(
        User $user,
        string $targetDate,
        string $routeCode,
        string $shiftCode,
        string $details,
        string $source = 'mobile',
        ?string $explicitTab = null,
        ?string $operationId = null
    ): array {
        $tabNumber = self::resolveTabNumber($user, $explicitTab);

        $dt = Carbon::parse($targetDate);
        $monthStr = $dt->format('Y-m');
        $cleanRoute = trim($routeCode);
        $cleanShift = trim($shiftCode);
        $cleanDetails = trim($details);
        $cleanOpId = !empty($operationId) ? strtolower(trim($operationId)) : null;

        // Fingerprint нормализованного тела запроса
        $normalizedPayload = [
            'user_id' => $user->id,
            'tab_number' => $tabNumber,
            'target_date' => $dt->format('Y-m-d'),
            'route_code' => $cleanRoute,
            'shift_code' => $cleanShift,
            'details' => $cleanDetails,
        ];
        $payloadHash = hash('sha256', json_encode($normalizedPayload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        // Атомарная транзакция с блокировкой пользователя
        return DB::transaction(function () use (
            $user,
            $tabNumber,
            $dt,
            $monthStr,
            $cleanRoute,
            $cleanShift,
            $cleanDetails,
            $source,
            $cleanOpId,
            $payloadHash
        ) {
            // Блокировка пользователя для исключения состояния гонки по лимиту
            User::whereKey($user->id)->lockForUpdate()->first();

            // Проверка квитанции идемпотентности под блокировкой пользователя
            if ($cleanOpId !== null && Schema::hasTable('podstroika_operations')) {
                $receipt = DB::table('podstroika_operations')
                    ->where('user_id', $user->id)
                    ->where('operation_id', $cleanOpId)
                    ->first();

                if ($receipt) {
                    if (!hash_equals($receipt->payload_hash, $payloadHash)) {
                        abort(409, 'Ключ операции (operation_id) уже использован для другого запроса.');
                    }
                    return json_decode($receipt->response, true, 512, JSON_THROW_ON_ERROR);
                }
            }

            // Повторная валидация лимита и условий под блокировкой
            [$isValid, $error, $warning] = self::validateConditions($tabNumber, $dt->format('Y-m-d'), $cleanRoute, $cleanShift, $user->id);
            if (!$isValid) {
                return [
                    'success' => false,
                    'message' => $error,
                    'data' => null,
                ];
            }

            $conflictReason = $warning ? $warning['message'] : null;
            $startOfMonth = $dt->copy()->startOfMonth();

            // 1. Сохранение в каноническую модель Podstroika
            $fullDetails = "[М-{$cleanRoute} см.{$cleanShift}] " . $cleanDetails;
            $podstroikaData = [
                'user_id' => $user->id,
                'for_month' => $startOfMonth,
                'target_date' => $dt->format('Y-m-d'),
                'route_code' => $cleanRoute,
                'shift_code' => $cleanShift,
                'details' => $fullDetails,
                'status' => 'pending',
                'source' => $source,
                'conflict_reason' => $conflictReason,
            ];
            if (Schema::hasColumn('podstroikas', 'operation_id')) {
                $podstroikaData['operation_id'] = $cleanOpId;
            }
            $podstroika = Podstroika::create($podstroikaData);

            // 2. Синхронная запись в arm_podstroiki (если таблица существует в данном окружении)
            // Ошибки SQL не подавляются, гарантируя целостность транзакции без половинчатых записей
            if (Schema::hasTable('arm_podstroiki')) {
                DB::table('arm_podstroiki')->insert([
                    'id' => $podstroika->id, // Согласованный единый ID
                    'tab_number' => $tabNumber,
                    'target_date' => $dt->format('Y-m-d'),
                    'route_code' => $cleanRoute,
                    'shift_code' => $cleanShift,
                    'details' => $cleanDetails,
                    'status' => 'PENDING',
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);
            }

            // 3. Отправка в Redis Pub/Sub строго ПОСЛЕ коммита транзакции
            DB::afterCommit(function () use ($podstroika, $tabNumber, $dt, $cleanRoute, $cleanShift, $source, $conflictReason) {
                self::publishRedisEvent([
                    'event' => 'podstroika_created',
                    'id' => $podstroika->id,
                    'tab' => $tabNumber,
                    'date' => $dt->format('Y-m-d'),
                    'route' => $cleanRoute,
                    'shift' => $cleanShift,
                    'source' => $source,
                    'warning' => $conflictReason,
                ]);
            });

            $limitStatus = self::getLimitStatus($tabNumber, $user->id, $monthStr);

            $result = [
                'success' => true,
                'message' => 'Заявка на подстройку смены успешно зарегистрирована.',
                'warning' => $conflictReason,
                'podstroika' => [
                    'id' => $podstroika->id,
                    'tab_number' => $tabNumber,
                    'target_date' => $dt->format('Y-m-d'),
                    'route_code' => $cleanRoute,
                    'shift_code' => $cleanShift,
                    'details' => $cleanDetails,
                    'status' => 'PENDING',
                    'status_label' => 'В ожидании',
                    'source' => $source,
                    'warning' => $conflictReason,
                    'operation_id' => $cleanOpId,
                    'created_at' => Carbon::now()->toIso8601String(),
                ],
                'limit' => $limitStatus,
            ];

            // Сохраняем квитанцию операции для идемпотентных повторов
            if ($cleanOpId !== null && Schema::hasTable('podstroika_operations')) {
                DB::table('podstroika_operations')->insert([
                    'user_id' => $user->id,
                    'operation_id' => $cleanOpId,
                    'payload_hash' => $payloadHash,
                    'response' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);
            }

            return $result;
        });
    }

    /**
     * Отозвать (отменить) поданную заявку безопасно.
     * Не допускает частичного изменения: блокирует и проверяет обе таблицы ДО первого UPDATE.
     * Определяет табельный номер владельца заявки (а не исполнителя).
     */
    public static function cancelPodstroika(int $id, User $user): array
    {
        return DB::transaction(function () use ($id, $user) {
            // 1. Блокируем каноническую запись podstroika
            $podstroika = Podstroika::where('id', $id)->lockForUpdate()->first();
            if (!$podstroika) {
                return ['success' => false, 'message' => 'Заявка не найдена.'];
            }

            // Проверяем права: владелец, диспетчер или администратор
            if ($podstroika->user_id !== $user->id && !$user->isDispatcher() && !$user->isAdmin()) {
                return ['success' => false, 'message' => 'У вас нет прав на отмену этой заявки.'];
            }

            // Проверяем статус канонической записи
            if (in_array(strtolower($podstroika->status), ['podstroeno', 'applied'])) {
                return ['success' => false, 'message' => 'Нельзя отменить заявку, которая уже занесена в наряд.'];
            }

            if (in_array(strtolower($podstroika->status), ['cancelled', 'rejected'])) {
                return ['success' => false, 'message' => 'Заявка уже отозвана или отклонена.'];
            }

            // Определяем табельный номер владельца заявки (а не администратора, если отменяет админ)
            $ownerUser = ($podstroika->user_id === $user->id) ? $user : User::find($podstroika->user_id);
            $ownerTabNumber = $ownerUser ? self::resolveTabNumber($ownerUser) : null;

            // 2. Блокируем и проверяем зеркальную строку ДО внесения любых изменений
            $mirrorRow = null;
            if (Schema::hasTable('arm_podstroiki') && !empty($ownerTabNumber)) {
                $mirrorRow = DB::table('arm_podstroiki')
                    ->where('id', $id)
                    ->where('tab_number', $ownerTabNumber)
                    ->lockForUpdate()
                    ->first();

                if ($mirrorRow && in_array(strtoupper($mirrorRow->status), ['APPLIED', 'PODSTROENO'])) {
                    // Если в зеркале уже занесено в наряд, отменять НЕЛЬЗЯ!
                    // Никаких изменений не вносим ни в одну таблицу!
                    return ['success' => false, 'message' => 'Нельзя отменить заявку, которая уже занесена в наряд.'];
                }
            }

            // 3. Только после того как ВСЕ проверки пройдены, обновляем обе таблицы
            $podstroika->update([
                'status' => 'cancelled',
                'updated_at' => Carbon::now(),
            ]);

            if ($mirrorRow) {
                DB::table('arm_podstroiki')
                    ->where('id', $id)
                    ->where('tab_number', $ownerTabNumber)
                    ->update([
                        'status' => 'CANCELLED',
                        'updated_at' => Carbon::now(),
                    ]);
            }

            DB::afterCommit(function () use ($id, $ownerTabNumber) {
                self::publishRedisEvent([
                    'event' => 'podstroika_cancelled',
                    'id' => $id,
                    'tab' => $ownerTabNumber,
                ]);
            });

            return ['success' => true, 'message' => 'Заявка успешно отозвана.'];
        });
    }

    /**
     * Публикация события в Redis шину `arm:grid:updates`.
     */
    public static function publishRedisEvent(array $payload): void
    {
        try {
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
            $socket = @fsockopen(env('REDIS_HOST', '127.0.0.1'), (int)env('REDIS_PORT', 6379), $errNo, $errStr, 1.5);
            if ($socket) {
                $cmd = "*3\r\n$7\r\nPUBLISH\r\n$16\r\narm:grid:updates\r\n$" . strlen($json) . "\r\n$json\r\n";
                fwrite($socket, $cmd);
                fclose($socket);
            }
        } catch (\Throwable $e) {
            Log::debug("Redis publish skipped: " . $e->getMessage());
        }
    }
}
