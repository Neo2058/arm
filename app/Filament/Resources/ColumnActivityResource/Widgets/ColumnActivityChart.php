<?php

namespace App\Filament\Resources\ColumnActivityResource\Widgets;

use Filament\Widgets\ChartWidget;
use ClickHouseDB\Client;

class ColumnActivityChart extends ChartWidget
{
    protected static ?string $heading = 'Активность колонн (Просмотры документов)';
    protected static ?int $sort = 1; // Порядок отображения на главной

    protected function getData(): array
    {
        // 1. Подключаемся к ClickHouse
        $ch = new Client(config('clickhouse'));

        // 2. Делаем агрегирующий запрос: группируем просмотры по колоннам
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
            $labels[] = 'Колонна № ' . $row['user_column'];
            $data[] = $row['total'];
        }

        // Если данных еще нет, покажем заглушку
        if (empty($data)) {
            $labels = ['Нет данных'];
            $data = [0];
        }

        return [
            'datasets' => [
                [
                    'label' => 'Количество просмотров',
                    'data' => $data,
                    'backgroundColor' => '#f97316', // Оранжевый цвет в стиле нашего бренда
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'bar'; //Типы графиков: столбцы
    }
}
