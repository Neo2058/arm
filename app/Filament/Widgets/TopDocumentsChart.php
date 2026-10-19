<?php

namespace App\Filament\Widgets;

use App\Services\ClickHouseService;
use Filament\Widgets\ChartWidget;

class TopDocumentsChart extends ChartWidget
{
    protected static ?string $heading = 'Топ читаемых документов';

    protected static ?int $sort = 2;

    protected function getData(): array
    {
        $rows = ClickHouseService::select("
            SELECT details as doc_name, count() as total
            FROM default.user_actions
            WHERE action_type = 'view_document'
            GROUP BY details
            ORDER BY total DESC
            LIMIT 5
        ");

        $labels = [];
        $data = [];

        foreach ($rows as $row) {
            $labels[] = $row['doc_name'];
            $data[] = $row['total'];
        }

        if ($data === []) {
            $labels = ['Нет данных'];
            $data = [0];
        }

        return [
            'datasets' => [
                [
                    'data' => $data,
                    'backgroundColor' => [
                        '#3b82f6', '#06b6d4', '#10b981', '#f59e0b', '#ef4444',
                    ],
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'doughnut';
    }
}
