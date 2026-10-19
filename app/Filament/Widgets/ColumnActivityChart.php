<?php

namespace App\Filament\Widgets;

use App\Services\ClickHouseService;
use Filament\Widgets\ChartWidget;

class ColumnActivityChart extends ChartWidget
{
    protected static ?string $heading = 'Активность колонн (просмотры документов)';

    protected static ?int $sort = 1;

    protected function getData(): array
    {
        $rows = ClickHouseService::select("
            SELECT user_column, count() as total
            FROM default.user_actions
            WHERE action_type = 'view_document'
            GROUP BY user_column
            ORDER BY total DESC
        ");

        $labels = [];
        $data = [];

        foreach ($rows as $row) {
            $labels[] = 'Колонна № '.$row['user_column'];
            $data[] = $row['total'];
        }

        if ($data === []) {
            $labels = ['Нет данных'];
            $data = [0];
        }

        return [
            'datasets' => [
                [
                    'label' => 'Количество просмотров',
                    'data' => $data,
                    'backgroundColor' => '#f97316',
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
