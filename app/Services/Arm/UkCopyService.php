<?php

namespace App\Services\Arm;

use App\Models\ArmUkCopy;
use App\Models\NaryadAssignment;
use Carbon\Carbon;

/**
 * Копии учётных карточек после закрытия ЛС (KOP1/KOP2).
 */
class UkCopyService
{
    public function snapshotMonth(string $yearMonth): int
    {
        $start = Carbon::createFromFormat('Y-m', $yearMonth)->startOfMonth();
        $end = $start->copy()->endOfMonth();
        ArmUkCopy::query()->where('year_month', $yearMonth)->delete();

        $rows = NaryadAssignment::query()
            ->whereBetween('plan_date', [$start->toDateString(), $end->toDateString()])
            ->get();

        $now = now();
        $chunk = [];
        foreach ($rows as $row) {
            $chunk[] = $this->payload($row, $yearMonth, $now);
            if (count($chunk) >= 200) {
                ArmUkCopy::insert($chunk);
                $chunk = [];
            }
        }
        if ($chunk !== []) {
            ArmUkCopy::insert($chunk);
        }

        return $rows->count();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(NaryadAssignment $row, string $yearMonth, $now): array
    {
        return [
            'year_month' => $yearMonth,
            'user_id' => $row->user_id,
            'plan_date' => $row->plan_date?->toDateString(),
            'route_number' => (string) ($row->route_number ?? ''),
            'work_code' => $row->work_code,
            'shift_code' => $row->shift_code,
            'registered' => (bool) $row->registered,
            'two_person' => (bool) $row->two_person,
            'hours_total' => $row->hours_total,
            'hours_line' => $row->hours_line,
            'hours_reserve' => $row->hours_reserve,
            'hours_night' => $row->hours_night,
            'hours_night_reserve' => $row->hours_night_reserve,
            'hours_evening' => $row->hours_evening,
            'hours_evening_reserve' => $row->hours_evening_reserve,
            'hours_break' => $row->hours_break,
            'hours_break_reserve' => $row->hours_break_reserve,
            'hours_holiday' => $row->hours_holiday,
            'hours_holiday_reserve' => $row->hours_holiday_reserve,
            'hours_overtime' => $row->hours_overtime,
            'hours_total_2' => $row->hours_total_2,
            'hours_line_2' => $row->hours_line_2,
            'hours_reserve_2' => $row->hours_reserve_2,
            'hours_night_2' => $row->hours_night_2,
            'hours_night_2_reserve' => $row->hours_night_2_reserve,
            'hours_evening_2' => $row->hours_evening_2,
            'hours_evening_2_reserve' => $row->hours_evening_2_reserve,
            'hours_break_2' => $row->hours_break_2,
            'hours_break_2_reserve' => $row->hours_break_2_reserve,
            'hours_holiday_2' => $row->hours_holiday_2,
            'hours_holiday_2_reserve' => $row->hours_holiday_2_reserve,
            'hours_line_2_reserve' => $row->hours_line_2_reserve,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
}
