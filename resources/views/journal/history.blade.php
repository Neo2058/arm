@extends('teaching.layouts.index')

@section('content')
<div class="max-w-7xl mx-auto px-4 pt-2 pb-6 lg:pt-6 lg:px-6">
    <h1 class="text-2xl font-bold mb-6 text-gray-900 dark:text-gray-200 pl-6 md:pl-0">История нормативов (Колонна {{ $column ?? '—' }})</h1>

    <div class="bg-white dark:bg-gray-800 rounded-xl p-6 border">
        <div class="mb-4 flex flex-wrap gap-4">
            <input type="text" id="history-search" placeholder="Поиск по ID пользователя или деталям" class="border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white p-2 rounded text-sm focus:outline-none focus:ring-1 focus:ring-orange-500" onkeyup="filterHistory()">
            <select id="history-type" class="border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white p-2 rounded text-sm focus:outline-none focus:ring-1 focus:ring-orange-500" onchange="filterHistory()">
                <option value="">Все типы</option>
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
                @foreach($typeLabels as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                @endforeach
            </select>
            <input type="date" id="history-from" class="border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white p-2 rounded text-sm focus:outline-none focus:ring-1 focus:ring-orange-500" onchange="filterHistory()">
            <input type="date" id="history-to" class="border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white p-2 rounded text-sm focus:outline-none focus:ring-1 focus:ring-orange-500" onchange="filterHistory()">
            <button type="button" onclick="filterHistory()" class="bg-orange-500 hover:bg-orange-600 text-white px-3 py-1 rounded text-sm transition-colors">Применить</button>
            <button type="button" onclick="clearHistoryFilters()" class="bg-gray-500 hover:bg-gray-600 text-white px-3 py-1 rounded text-sm transition-colors">Сброс</button>
        </div>

        @if(count($history) > 0)
            <div class="overflow-x-auto -mx-1">
            <table id="history-table" class="min-w-full text-sm min-w-[640px]">
                <thead>
                    <tr class="border-b bg-gray-100 dark:bg-gray-700">
                        <th class="p-2 text-left text-gray-900 dark:text-gray-200">Время</th>
                        <th class="p-2 text-gray-900 dark:text-gray-200">Пользователь</th>
                        <th class="p-2 text-gray-900 dark:text-gray-200">Тип</th>
                        <th class="p-2 text-gray-900 dark:text-gray-200">Детали</th>
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
                        <tr class="border-t" data-time="{{ $row['event_time'] }}" data-type="{{ $type }}">
                            <td class="p-2 text-gray-900 dark:text-gray-200">{{ $row['event_time'] }}</td>
                            <td class="p-2 text-gray-900 dark:text-gray-200">{{ $userMap[$row['user_id']] ?? $row['user_id'] }}</td>
                            <td class="p-2 text-gray-900 dark:text-gray-200">{{ $typeLabels[$type] ?? $type }}</td>
                            <td class="p-2 text-xs text-gray-900 dark:text-gray-200">{{ is_array($details) ? json_encode($details, JSON_UNESCAPED_UNICODE) : $details }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        @else
            <p class="text-gray-500">История пока пуста. Выполненные нормативы будут логироваться в ClickHouse.</p>
            <p class="text-xs mt-2 text-orange-500">Для демо: используйте ClickHouseService::log('normative_completed', $userId, ['type' => '...']);</p>
        @endif
    </div>
</div>

<script>
function filterHistory() {
    const search = document.getElementById('history-search').value.toLowerCase();
    const typeFilter = document.getElementById('history-type').value;
    const from = document.getElementById('history-from').value;
    const to = document.getElementById('history-to').value;
    const rows = document.querySelectorAll('#history-table tbody tr');
    rows.forEach(row => {
        const text = row.textContent.toLowerCase();
        const type = row.dataset.type || '';
        const time = row.dataset.time || '';
        let visible = true;
        if (search && !text.includes(search)) visible = false;
        if (typeFilter && type !== typeFilter) visible = false;
        if (from && time < from) visible = false;
        if (to && time > to) visible = false;
        row.style.display = visible ? '' : 'none';
    });
}

function clearHistoryFilters() {
    document.getElementById('history-search').value = '';
    document.getElementById('history-type').value = '';
    document.getElementById('history-from').value = '';
    document.getElementById('history-to').value = '';
    filterHistory();
}

</script>
@endsection