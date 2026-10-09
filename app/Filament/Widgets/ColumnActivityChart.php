<?php

namespace App\Filament\Widgets;

use ClickHouseDB\Client;
use Filament\Widgets\ChartWidget;

class ColumnActivityChart extends ChartWidget
{
    protected static ?string $heading = 'Активность колонн (просмотры документов)';

    protected static ?int $sort = 1;

    protected function getData(): array
    {
        $ch = new Client(config('clickhouse'));

        $result = $ch->select("
            SELECT user_column, count() as total
            FROM default.user_actions
            WHERE action_type = 'view_document'
            GROUP BY user_column
            ORDER BY total DESC
        ");

        $labels = [];
        $data = [];

        foreach ($result->rows() as $row) {
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
