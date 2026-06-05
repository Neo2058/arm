<?php

namespace App\Filament\Resources\MonthlyColumnAnalyticsResource\Widgets;

use Filament\Widgets\ChartWidget;
use ClickHouseDB\Client;

class MonthlyColumnAnalytics extends ChartWidget
{
    protected static ?string $heading = 'Ежемесячная аналитика успеваемости по колоннам';
    protected static ?int $sort = 3;

    // Свойство для хранения выбранного смещения месяца (0 - текущий, -1 - прошлый и т.д.)
    // Filament автоматически обновляет график при изменении public свойств через wire:model
    public ?int $monthOffset = 0;

    protected function getData(): array
    {
        $ch = new Client(config('clickhouse'));

        // Вычисляем целевой месяц и год на основе выбранного фильтра
        $offset = $this->filter ?? 0;
        $targetDate = date('Y-m-d', strtotime("$offset month"));
        $targetYearMonth = date('Ym', strtotime("$offset month"));
        $monthName = date('F Y', strtotime("$offset month"));

        // Запрос к ClickHouse:
        // 1. Фильтруем по типу события (complete_quiz) и целевому месяцу
        // 2. Считаем общее число уникальных машинистов из колонны (passed_count)
        // 3. Вычисляем средний процент успеваемости по формуле (JSONExtractFloat достает данные из details)
        $result = $ch->select("
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
        $descriptions = [];

        foreach ($result->rows() as $row) {
            $columnNum = $row['user_column'];
            $count = $row['passed_count'];
            $avg = round($row['avg_percent'], 1);

            $labels[] = "Колонна №{$columnNum} ({$count} чел.)";
            $avgPercents[] = $avg;
        }

        // Если в выбранном месяце никто не сдавал тесты
        if (empty($avgPercents)) {
            return [
                'datasets' => [[
                    'label' => 'Нет данных за ' . $monthName,
                    'data' => [],
                ]],
                'labels' => [],
            ];
        }

        return [
            'datasets' => [
                [
                    'label' => 'Средний балл успеваемости (%) в ' . $monthName,
                    'data' => $avgPercents,
                    'backgroundColor' => '#3b82f6', // Синий цвет для графиков успеваемости
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
            '-6' => ' Полгода назад',
        ];
    }
}
