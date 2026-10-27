<?php

namespace App\Services\Payroll;

use App\Models\NaryadNorm;
use App\Models\WorkTimeMonthSettings;
use App\Services\Arm\FoxTime;
use App\Services\Arm\PlanirRulesService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PersonalWorkTimeSettings
{
    public function snapshot(int $userId, string $month): array
    {
        $norm = NaryadNorm::first();
        $planir = app(PlanirRulesService::class)->settings($norm);
        $settings = [
            'timezone' => 'Europe/Moscow',
            'line_rate_kopecks' => 57348,
            'reserve_rate_kopecks' => 55300,
            'surface_rate_kopecks' => 51204,
            'evening_percent' => 20,
            'night_percent' => 40,
            'evening_from_minute' => isset($planir['evening_from']) ? FoxTime::toMinutes($planir['evening_from']) : 960,
            'evening_to_minute' => isset($planir['evening_to']) ? FoxTime::toMinutes($planir['evening_to']) : 1320,
            'night_from_minute' => isset($planir['night_from']) ? FoxTime::toMinutes($planir['night_from']) : 1320,
            'night_to_minute' => isset($planir['night_to']) ? FoxTime::toMinutes($planir['night_to']) : 360,
            'rounding_decimals' => 1,
            'night_majority' => true,
            'night_pair_merge' => ($planir['merge_night'] ?? '+') === '+',
            'night_pair_codes' => $planir['night_count_codes'] ?? ['3+', '4+'],
            'rest_rules' => array_intersect_key($planir, array_flip(['interval_mode', 'interval_hours', 'rest_day_hours', 'two_rest_days_hours'])),
            'source' => 'personal-v2-confirmed-rates-armnrd-windows',
        ];
        $month = CarbonImmutable::parse($month)->startOfMonth()->toDateString();
        $saved = WorkTimeMonthSettings::where('user_id', $userId)->whereDate('month', '<=', $month)->orderBy('month')->get();
        // Rate changes take effect from their month onward; rest dates never carry over.
        foreach ($saved as $record) {
            foreach (['line_rate_kopecks', 'reserve_rate_kopecks', 'surface_rate_kopecks', 'evening_percent', 'night_percent'] as $key) {
                if (array_key_exists($key, $record->settings ?? [])) {
                    $settings[$key] = $record->settings[$key];
                }
            }
        }
        $settings['month'] = $month;
        $this->validate($settings);

        return $settings;
    }

    public function validate(array $settings): void
    {
        Validator::make($settings, [
            'timezone' => 'required|timezone',
            'line_rate_kopecks' => 'required|integer|min:0|max:10000000',
            'reserve_rate_kopecks' => 'required|integer|min:0|max:10000000',
            'surface_rate_kopecks' => 'required|integer|min:0|max:10000000',
            'evening_percent' => 'required|integer|min:0|max:100',
            'night_percent' => 'required|integer|min:0|max:100',
            'evening_from_minute' => 'required|integer|min:0|max:1439|different:evening_to_minute',
            'evening_to_minute' => 'required|integer|min:0|max:1439',
            'night_from_minute' => 'required|integer|min:0|max:1439|different:night_to_minute',
            'night_to_minute' => 'required|integer|min:0|max:1439',
            'rounding_decimals' => 'required|integer|min:1|max:2',
            'night_majority' => 'required|boolean',
            'night_pair_merge' => 'sometimes|boolean',
            'night_pair_codes' => 'sometimes|array|min:1|max:8',
            'night_pair_codes.*' => 'required|string|max:8',
            'rest_rules.interval_mode' => 'required|in:formula,fixed',
            'rest_rules.interval_hours' => 'required|numeric|min:12',
            'rest_rules.rest_day_hours' => 'required|numeric|min:0',
            'rest_rules.two_rest_days_hours' => 'required|numeric|min:0',
        ])->validate();
        for ($minute = 0; $minute < 1440; $minute++) {
            $inside = static fn (int $from, int $to) => $from < $to
                ? $minute >= $from && $minute < $to
                : $minute >= $from || $minute < $to;
            if ($inside($settings['evening_from_minute'], $settings['evening_to_minute'])
                && $inside($settings['night_from_minute'], $settings['night_to_minute'])) {
                throw ValidationException::withMessages(['settings' => 'Периоды вечерних и ночных часов не должны пересекаться.']);
            }
        }
    }
}
