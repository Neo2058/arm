<?php

namespace App\Filament\Resources\TopDocumentsResource\Widgets;

use Filament\Widgets\ChartWidget;
use ClickHouseDB\Client;

class TopDocumentsChart extends ChartWidget
{
    protected static ?string $heading = 'Топ читаемых документов';
    protected static ?int $sort = 2;

    protected function getData(): array
    {
        $ch = new Client(config('clickhouse'));

        // Запрос: выбираем топ-5 документов по количеству просмотров
        $result = $ch->select("
            SELECT details as doc_name, count() as total
            FROM default.user_actions
            WHERE action_type = 'view_document'
            GROUP BY details
            ORDER BY total DESC
            LIMIT 5
        ");

        $labels = [];
        $data = [];

        foreach ($result->rows() as $row) {
            $labels[] = $row['doc_name'];
            $data[] = $row['total'];
        }

        return [
            'datasets' => [
                [
                    'data' => $data,
                    'backgroundColor' => [
                        '#3b82f6', '#06b6d4', '#10b981', '#f59e0b', '#ef4444'
                    ], // Набор ярких цветов для секторов
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'doughnut'; // Круговая диаграмма с вырезом
    }
}
