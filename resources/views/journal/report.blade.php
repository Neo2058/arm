@extends('teaching.layouts.index')

@section('content')
<div class="max-w-7xl mx-auto px-4 pt-2 pb-6 lg:pt-6 lg:px-6">
    <h1 class="text-2xl font-bold mb-6 text-gray-900 dark:text-gray-200 pl-6 md:pl-0">Отчёт по нормативам - Колонна {{ $column }}</h1>

    @if(session('success'))
        <div class="bg-green-100 p-3 mb-4 rounded">{{ session('success') }}</div>
    @endif
    <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">Фильтры применяются к таблицам нормативов. Добавляйте задачи в TODO. Управляйте отпусками в соответствующей вкладке.</p>

    <!-- Filters -->
    <div class="mb-4 p-3 bg-white dark:bg-gray-800 border rounded-xl">
        <div class="flex flex-wrap gap-2 items-end">
            <div class="flex-1 min-w-[180px]">
                <label class="block text-xs text-gray-600 dark:text-gray-400 mb-1">Поиск (ФИО, дата)</label>
                <input type="text" id="report-search" placeholder="Иванов или 15.07" 
                       class="w-full border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white rounded px-3 py-1.5 text-sm focus:outline-none focus:ring-1 focus:ring-orange-500"
                       onkeyup="filterReport()">
            </div>
            <div>
                <label class="block text-xs text-gray-600 dark:text-gray-400 mb-1">С даты</label>
                <input type="date" id="report-from" class="border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white rounded px-2 py-1.5 text-sm focus:outline-none focus:ring-1 focus:ring-orange-500" onchange="filterReport()">
            </div>
            <div>
                <label class="block text-xs text-gray-600 dark:text-gray-400 mb-1">По дату</label>
                <input type="date" id="report-to" class="border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white rounded px-2 py-1.5 text-sm focus:outline-none focus:ring-1 focus:ring-orange-500" onchange="filterReport()">
            </div>
            <button type="button" onclick="clearReportFilters()" class="bg-gray-500 hover:bg-gray-600 text-white px-3 py-1.5 rounded text-sm">Сброс</button>
        </div>
    </div>

    <div class="mb-4">
        <!-- Tab buttons -->
        <div class="flex border-b mb-4 overflow-x-auto">
            @foreach($types as $type)
                <button type="button" onclick="showTab('{{ $type }}')" class="tab-button px-3 py-2 text-sm border-b-2 {{ $loop->first ? 'border-orange-500 text-orange-500' : 'border-transparent' }}" data-tab="{{ $type }}">
                    {{ $normativeLabels[$type] ?? ucfirst(str_replace('_', ' ', $type)) }}
                </button>
            @endforeach
            <button type="button" onclick="showTab('vacations')" class="tab-button px-3 py-2 text-sm border-b-2 border-transparent" data-tab="vacations">
                Отпуска
            </button>
            <button type="button" onclick="showTab('intersections')" class="tab-button px-3 py-2 text-sm border-b-2 border-transparent" data-tab="intersections">
                Отчёт по пересечениям
            </button>
        </div>

        <!-- Normative type tabs -->
        @foreach($types as $type)
            <div id="tab-{{ $type }}" class="tab-content {{ $loop->first ? '' : 'hidden' }}">
                <div class="bg-white dark:bg-gray-800 rounded-xl overflow-hidden border">
                    <div class="overflow-x-auto -mx-1">
                    <table class="min-w-full text-sm bg-white dark:bg-gray-800 rounded-xl overflow-hidden min-w-[520px]">
                        <thead>
                            <tr class="bg-gray-100 dark:bg-gray-700">
                                <th class="p-2 text-left text-gray-900 dark:text-gray-200">ФИО</th>
                                <th class="p-2 text-gray-900 dark:text-gray-200">Дата следующего</th>
                                <th class="p-2 text-gray-900 dark:text-gray-200"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($crew as $member)
                                @php 
                                    $norm = $normatives->get($member->id, collect())->firstWhere('type', $type);
                                    $nextStr = $norm && $norm->next_date ? $norm->next_date->format('Y-m-d') : '';
                                    $nextDisplay = $norm && $norm->next_date ? $norm->next_date->format('d.m.Y') : '';
                                @endphp
                                <tr class="border-t report-row" 
                                    data-name="{{ strtolower($member->name) }}" 
                                    data-date="{{ $nextStr }}">
                                    <td class="p-2 text-gray-900 dark:text-gray-200">{{ $member->name }}</td>
                                    <td class="p-2 text-gray-900 dark:text-gray-200">
                                        @if($norm && $norm->next_date)
                                            {{ $nextDisplay }}
                                        @else
                                            <span class="text-gray-400">Не задано</span>
                                        @endif
                                    </td>
                                    <td class="p-2">
                                        @if($norm && $norm->next_date)
                                            <form action="{{ route('journal.todo.add') }}" method="POST" class="inline">
                                                @csrf
                                                <input type="hidden" name="title" value="Выполнить {{ $normativeLabels[$type] ?? $type }} для {{ $member->name }} до {{ $nextDisplay }}">
                                                <input type="hidden" name="description" value="Согласно рабочему журналу ТЧМ. Последняя дата: {{ $norm->last_date ? $norm->last_date->format('d.m.Y') : 'не указана' }}. Класс: {{ $member->profile?->normative_class ?? 'не указан' }}.">
                                                <input type="hidden" name="due_date" value="{{ $norm->next_date->format('Y-m-d') }}">
                                                <input type="hidden" name="source" value="norm">
                                                <button type="submit" class="bg-orange-500 text-white px-3 py-1 rounded text-xs">Добавить в TODO</button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    </div>
                </div>
            </div>
        @endforeach

        <!-- Vacations tab -->
        <div id="tab-vacations" class="tab-content hidden">
            <div class="bg-white dark:bg-gray-800 rounded-xl p-4 border">
                <h3 class="font-semibold mb-3 text-orange-500">Управление отпусками (влияют на пересечения с КИП)</h3>
                <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">Добавляйте периоды отпусков. Если дата следующего КИП попадает в период отпуска — человек попадёт в «Отчёт по пересечениям».</p>

                @foreach($crew as $member)
                    @php $memberVacs = $vacations->get($member->id, collect()); @endphp
                    <div class="mb-5 border border-gray-200 dark:border-gray-700 rounded-lg p-3">
                        <div class="font-medium mb-2 text-gray-900 dark:text-gray-200">{{ $member->name }}</div>

                        @if($memberVacs->count())
                            <div class="space-y-1 mb-3">
                                @foreach($memberVacs as $vac)
                                    <div class="flex items-center justify-between text-sm bg-gray-50 dark:bg-gray-900 rounded px-2 py-1 text-gray-900 dark:text-gray-200">
                                        <span>
                                            {{ $vac->start_date->format('d.m.Y') }} — {{ $vac->end_date->format('d.m.Y') }}
                                            @if($vac->type) <span class="text-xs text-gray-500 dark:text-gray-400">({{ $vac->type }})</span> @endif
                                        </span>
                                        <form action="{{ route('journal.report.vacation.delete', $vac->id) }}" method="POST" class="inline" onsubmit="return confirm('Удалить период отпуска?');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="text-red-600 dark:text-red-400 hover:text-red-700 dark:hover:text-red-300 text-xs">🗑 Удалить</button>
                                        </form>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <div class="text-xs text-gray-400 dark:text-gray-500 mb-2">Нет добавленных отпусков</div>
                        @endif

                        <!-- Add form for this member -->
                        <form action="{{ route('journal.report.vacation.add') }}" method="POST" class="flex flex-wrap gap-2 items-end text-sm">
                            @csrf
                            <input type="hidden" name="user_id" value="{{ $member->id }}">
                            <div>
                                <label class="block text-[10px] text-gray-500 dark:text-gray-400">Начало</label>
                                <input type="date" name="start_date" class="border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white rounded px-2 py-1 text-xs" required>
                            </div>
                            <div>
                                <label class="block text-[10px] text-gray-500 dark:text-gray-400">Окончание</label>
                                <input type="date" name="end_date" class="border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white rounded px-2 py-1 text-xs" required>
                            </div>
                            <div>
                                <label class="block text-[10px] text-gray-500 dark:text-gray-400">Тип</label>
                                <input type="text" name="type" value="основной" class="border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white rounded px-2 py-1 text-xs w-24">
                            </div>
                            <button type="submit" class="bg-orange-500 hover:bg-orange-600 text-white px-3 py-1 rounded text-xs mt-1">+ Добавить отпуск</button>
                        </form>
                    </div>
                @endforeach
            </div>
        </div>

        <!-- Intersections report tab -->
        <div id="tab-intersections" class="tab-content hidden">
            <div class="bg-white dark:bg-gray-800 rounded-xl p-4 border">
                <h3 class="font-semibold mb-2 text-red-600 dark:text-red-400">Отчёт по пересечениям</h3>
                <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">Люди, у которых дата следующего КИП попадает в период отпуска. Используйте для корректировки графиков.</p>

                @if(count($intersections) > 0)
                    <div class="overflow-x-auto">
                    <table class="min-w-full text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-200">
                        <thead>
                            <tr class="bg-gray-100 dark:bg-gray-700">
                                <th class="p-2 text-left text-gray-900 dark:text-gray-200">ФИО</th>
                                <th class="p-2 text-gray-900 dark:text-gray-200">Тип КИП</th>
                                <th class="p-2 text-gray-900 dark:text-gray-200">Дата КИП</th>
                                <th class="p-2 text-gray-900 dark:text-gray-200">Период отпуска</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($intersections as $item)
                                <tr class="border-t">
                                    <td class="p-2 font-medium text-gray-900 dark:text-gray-200">{{ $item['name'] }}</td>
                                    <td class="p-2 text-gray-900 dark:text-gray-200">{{ $item['type_label'] }}</td>
                                    <td class="p-2 text-gray-900 dark:text-gray-200">{{ $item['next_date']->format('d.m.Y') }}</td>
                                    <td class="p-2 text-xs text-gray-900 dark:text-gray-200">
                                        {{ $item['vacation_start']->format('d.m.Y') }} — {{ $item['vacation_end']->format('d.m.Y') }}
                                        @if(!empty($item['vacation_type'])) <span class="text-gray-500 dark:text-gray-400">({{ $item['vacation_type'] }})</span> @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    </div>
                @else
                    <p class="text-sm text-green-600 dark:text-green-400">Пересечений не найдено. Отлично!</p>
                @endif
            </div>
        </div>
    </div>
</div>

<script>
function showTab(tab) {
    document.querySelectorAll('.tab-content').forEach(el => el.classList.add('hidden'));
    const content = document.getElementById('tab-' + tab);
    if (content) content.classList.remove('hidden');

    document.querySelectorAll('.tab-button').forEach(el => {
        el.classList.remove('border-orange-500', 'text-orange-500');
        el.classList.add('border-transparent');
    });
    const active = document.querySelector(`[data-tab="${tab}"]`);
    if (active) {
        active.classList.remove('border-transparent');
        active.classList.add('border-orange-500', 'text-orange-500');
    }

    // Re-apply filters when switching to a normative tab
    if (!['vacations', 'intersections'].includes(tab)) {
        filterReport();
    }
}

function filterReport() {
    const search = (document.getElementById('report-search').value || '').toLowerCase().trim();
    const from = document.getElementById('report-from').value;
    const to = document.getElementById('report-to').value;

    document.querySelectorAll('.report-row').forEach(row => {
        const name = (row.dataset.name || '').toLowerCase();
        const dateStr = row.dataset.date || '';
        let visible = true;

        if (search) {
            const rowText = row.textContent.toLowerCase();
            if (!name.includes(search) && !rowText.includes(search)) {
                visible = false;
            }
        }

        if (visible && from && dateStr && dateStr < from) visible = false;
        if (visible && to && dateStr && dateStr > to) visible = false;

        row.style.display = visible ? '' : 'none';
    });
}

function clearReportFilters() {
    document.getElementById('report-search').value = '';
    document.getElementById('report-from').value = '';
    document.getElementById('report-to').value = '';
    document.querySelectorAll('.report-row').forEach(row => row.style.display = '');
    filterReport();
}

document.addEventListener('DOMContentLoaded', function() {
    // Run filter on initial load for the first visible tab (normative)
    setTimeout(function() {
        const hasRows = document.querySelector('.report-row');
        if (hasRows) filterReport();
    }, 80);
});
</script>
@endsection