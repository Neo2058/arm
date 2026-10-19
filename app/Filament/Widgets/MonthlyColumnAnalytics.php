<?php

namespace App\Filament\Widgets;

use App\Services\ClickHouseService;
use Filament\Widgets\ChartWidget;

class MonthlyColumnAnalytics extends ChartWidget
{
    protected static ?string $heading = 'Ежемесячная аналитика успеваемости по колоннам';

    protected static ?int $sort = 3;

    public ?int $monthOffset = 0;

    protected function getData(): array
    {
        $offset = (int) ($this->filter ?? 0);
        $monthName = date('F Y', strtotime("$offset month"));
        $targetYearMonth = date('Ym', strtotime("$offset month"));

        $rows = ClickHouseService::select("
            SELECT
                user_column,
                count(DISTINCT user_id) as passed_count,
                avg(JSONExtractFloat(details, 'percent')) as avg_percent
            FROM default.user_actions
            WHERE action_type = 'complete_quiz'
              AND toYYYYMM(event_date) = '{$targetYearMonth}'
            GROUP BY user_column
            ORDER BY user_column ASC
        ");

        $labels = [];
        $avgPercents = [];

        foreach ($rows as $row) {
            $labels[] = "Колонна №{$row['user_column']} ({$row['passed_count']} чел.)";
            $avgPercents[] = round($row['avg_percent'], 1);
        }

        if ($avgPercents === []) {
            return [
                'datasets' => [[
                    'label' => 'Нет данных за '.$monthName,
                    'data' => [],
                ]],
                'labels' => [],
            ];
        }

        return [
            'datasets' => [
                [
                    'label' => 'Средний балл успеваемости (%) в '.$monthName,
                    'data' => $avgPercents,
                    'backgroundColor' => '#3b82f6',
                    'borderRadius' => 8,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getFilters(): ?array
    {
        return [
            '0' => 'Текущий месяц',
            '-1' => '1 месяц назад',
            '-2' => '2 месяца назад',
            '-3' => '3 месяца назад',
            '-6' => 'Полгода назад',
        ];
    }
}
