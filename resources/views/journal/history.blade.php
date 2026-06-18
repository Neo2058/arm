@extends('teaching.layouts.index')

@section('content')
<div class="max-w-7xl mx-auto p-6">
    <h1 class="text-2xl font-bold mb-6">История нормативов (Колонна {{ $column ?? '—' }})</h1>

    <div class="bg-white dark:bg-gray-800 rounded-xl p-6 border">
        @if(count($history) > 0)
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="border-b">
                        <th class="p-2 text-left">Время</th>
                        <th class="p-2">Пользователь</th>
                        <th class="p-2">Тип</th>
                        <th class="p-2">Детали</th>
                    </tr>
                </thead>
                <tbody>
                    @php
                        $typeLabels = $normativeLabels ?? [
                            'kip_linia' => 'КИП Линия',
                            'kip_manevry' => 'КИП Манёвры',
                            'kip_podem' => 'КИП Подъём',
                            'kip_kru' => 'КИП КРУ',
                            'kip_ars_r' => 'КИП АРС-Р',
                            'kip_pnevmatika' => 'КИП Пневматика',
                            'kip_scep' => 'КИП Сцеп',
                            'atz' => 'АТЗ',
                            'atz_line' => 'АТЗ на Линии',
                        ];
                    @endphp
                    @foreach($history as $row)
                        @php
                            $details = is_string($row['details']) ? json_decode($row['details'], true) ?? $row['details'] : $row['details'];
                            $type = $details['type'] ?? $row['action_type'];
                        @endphp
                        <tr class="border-t">
                            <td class="p-2">{{ $row['event_time'] }}</td>
                            <td class="p-2">{{ $row['user_id'] }}</td>
                            <td class="p-2">{{ $typeLabels[$type] ?? $type }}</td>
                            <td class="p-2 text-xs">{{ is_array($details) ? json_encode($details, JSON_UNESCAPED_UNICODE) : $details }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <p class="text-gray-500">История пока пуста. Выполненные нормативы будут логироваться в ClickHouse.</p>
            <p class="text-xs mt-2 text-orange-500">Для демо: используйте ClickHouseService::log('normative_completed', $userId, ['type' => '...']);</p>
        @endif
    </div>
</div>
@endsection