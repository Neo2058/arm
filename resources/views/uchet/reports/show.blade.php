@extends('uchet.layouts.uchet')
@section('content')
    <style>
        @media print {
            aside, .no-print { display: none !important; }
            main { padding: 0 !important; }
            body { background: #fff; color: #000; }
            table { font-size: 11px; }
        }
        @page { size: A4 landscape; margin: 8mm; }
    </style>
    <div class="flex items-center justify-between mb-6 no-print">
        <div>
            <a href="{{ route('uchet.reports', ['month' => $month]) }}" class="text-sm text-orange-500">← К списку</a>
            <h1 class="text-2xl font-bold">{{ $report['title'] }}</h1>
            @if(!empty($report['subtitle']))
                <p class="text-sm text-orange-600">{{ $report['subtitle'] }}</p>
            @endif
        </div>
        <button type="button" class="px-4 py-2 border rounded-2xl" onclick="window.print()">Печать</button>
    </div>

    @if(!empty($report['message']))
        <div class="bg-white dark:bg-[#0b1018] border rounded-3xl px-4 py-6 text-orange-500">{{ $report['message'] }}</div>
    @endif

    @if(($report['layout'] ?? '') === 'hours')
        <div class="bg-white dark:bg-[#0b1018] border rounded-3xl overflow-auto mb-8">
            <table class="min-w-full text-sm">
                <thead class="border-b text-orange-600"><tr>
                    <th class="px-3 py-2 text-left">Таб.</th><th class="text-left">ФИО</th>
                    <th class="text-right px-3">Линия</th><th class="text-right px-3">Резерв</th>
                    <th class="text-right px-3">2 лицо</th><th class="text-right px-3">Ночь</th>
                    <th class="text-right px-3">Вечер</th><th class="text-right px-3">Праздник</th>
                    <th class="text-right px-3">Празд. 2 л.</th><th class="text-right px-3">Перераб.</th>
                </tr></thead>
                <tbody>
                @foreach($report['rows'] as $h)
                    <tr class="border-t">
                        <td class="px-3 py-1 font-mono">{{ $h['tab'] }}</td>
                        <td>{{ $h['name'] }}</td>
                        <td class="text-right px-3">{{ $h['line'] }}</td>
                        <td class="text-right px-3">{{ $h['reserve'] }}</td>
                        <td class="text-right px-3">{{ $h['line_2'] }}</td>
                        <td class="text-right px-3">{{ $h['night'] }}</td>
                        <td class="text-right px-3">{{ $h['evening'] }}</td>
                        <td class="text-right px-3">{{ $h['holiday'] }}</td>
                        <td class="text-right px-3">{{ $h['holiday_2'] }}</td>
                        <td class="text-right px-3">{{ $h['overtime'] }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <h2 class="font-semibold mb-2">Периоды отвлечений (OTVM)</h2>
        <div class="bg-white dark:bg-[#0b1018] border rounded-3xl overflow-hidden">
            <table class="min-w-full text-sm">
                <thead class="border-b text-orange-600"><tr>
                    <th class="px-3 py-2 text-left">Таб.</th><th class="text-left">ФИО</th><th>Вид</th><th>С</th><th>По</th>
                </tr></thead>
                <tbody>
                @forelse($report['absences'] as $a)
                    <tr class="border-t">
                        <td class="px-3 py-1 font-mono">{{ $a['tab'] }}</td>
                        <td>{{ $a['name'] }}</td>
                        <td>{{ $a['kind'] }}</td>
                        <td>{{ $a['from'] }}</td>
                        <td>{{ $a['to'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-3 py-4 text-orange-500">Нет периодов в этом месяце.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    @elseif(($report['layout'] ?? '') === 'matrix' && empty($report['message']))
        <div class="bg-white dark:bg-[#0b1018] border rounded-3xl overflow-auto">
            <table class="min-w-full text-xs font-mono">
                <thead class="border-b text-orange-600">
                <tr>
                    <th class="px-2 py-1 text-left">День/код</th>
                    @for($d = 1; $d <= $report['days']; $d++)
                        <th class="px-1 py-1">{{ sprintf('%02d', $d) }}</th>
                    @endfor
                    <th class="px-2 py-1">ИТОГО</th>
                </tr>
                </thead>
                <tbody>
                @foreach($report['blocks'] as $block)
                    <tr class="border-t">
                        <td class="px-2 py-1">{{ $block['code'] }} мш</td>
                        @for($d = 1; $d <= $report['days']; $d++)
                            <td class="text-right px-1">{{ $block['mash'][$d] ?: '' }}</td>
                        @endfor
                        <td class="text-right px-2">{{ $block['mash_total'] }}</td>
                    </tr>
                    <tr>
                        <td class="px-2 py-1 pl-6">п/м</td>
                        @for($d = 1; $d <= $report['days']; $d++)
                            <td class="text-right px-1">{{ $block['pom'][$d] ?: '' }}</td>
                        @endfor
                        <td class="text-right px-2">{{ $block['pom_total'] }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @elseif(($report['layout'] ?? '') === 'disability' && empty($report['message']))
        <p class="text-sm text-orange-600 mb-2">Коды Б/травма: {{ $report['codes'] }} (без воскресений и PRAZD)</p>
        <div class="bg-white dark:bg-[#0b1018] border rounded-3xl overflow-auto">
            <table class="min-w-full text-xs font-mono">
                <thead class="border-b text-orange-600">
                <tr>
                    <th class="px-2 py-1 text-left">Таб.</th>
                    <th class="text-left">ФИО</th>
                    @for($m = 1; $m <= 12; $m++)
                        <th colspan="2" class="text-center">{{ sprintf('%02d', $m) }}</th>
                    @endfor
                    <th>Б</th><th>Т</th>
                </tr>
                <tr>
                    <th></th><th></th>
                    @for($m = 1; $m <= 12; $m++)
                        <th>Б</th><th>Т</th>
                    @endfor
                    <th></th><th></th>
                </tr>
                </thead>
                <tbody>
                @foreach($report['rows'] as $row)
                    <tr class="border-t">
                        <td class="px-2 py-1">{{ $row['tab'] }}</td>
                        <td>{{ $row['name'] }}</td>
                        @for($m = 1; $m <= 12; $m++)
                            <td class="text-right">{{ $row['sick'][$m] ?: '' }}</td>
                            <td class="text-right">{{ $row['injury'][$m] ?: '' }}</td>
                        @endfor
                        <td class="text-right">{{ $row['sick_total'] }}</td>
                        <td class="text-right">{{ $row['injury_total'] }}</td>
                    </tr>
                @endforeach
                <tr class="border-t font-semibold">
                    <td colspan="26" class="text-right px-2 py-1">Итого</td>
                    <td class="text-right">{{ $report['total_sick'] }}</td>
                    <td class="text-right">{{ $report['total_injury'] }}</td>
                </tr>
                </tbody>
            </table>
        </div>
    @elseif(($report['layout'] ?? '') === 'work' && empty($report['message']))
        <div class="bg-white dark:bg-[#0b1018] border rounded-3xl overflow-auto">
            <table class="min-w-full text-sm">
                <thead class="border-b text-orange-600">
                <tr>
                    <th class="px-3 py-2 text-left">Таб.</th>
                    <th class="text-left">ФИО</th>
                    <th>Долж.</th>
                    @foreach($report['absence_codes'] as $code)
                        <th class="text-right px-2">{{ $code }}</th>
                    @endforeach
                    <th class="text-right px-2">Раб.</th>
                    <th class="text-right px-2">Часы</th>
                    <th class="text-right px-2">Пер.</th>
                    <th class="text-right px-2">Нед.</th>
                </tr>
                </thead>
                <tbody>
                @foreach($report['rows'] as $row)
                    <tr class="border-t">
                        <td class="px-3 py-1 font-mono">{{ $row['tab'] }}</td>
                        <td>{{ $row['name'] }}</td>
                        <td>{{ $row['position'] }}</td>
                        @foreach($report['absence_codes'] as $code)
                            <td class="text-right px-2">{{ $row['absences'][$code] ?? '' }}</td>
                        @endforeach
                        <td class="text-right px-2">{{ $row['work_days'] }}</td>
                        <td class="text-right px-2">{{ $row['hours'] }}</td>
                        <td class="text-right px-2">{{ $row['overtime'] ?: '' }}</td>
                        <td class="text-right px-2">{{ $row['underwork'] ?: '' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @elseif(($report['layout'] ?? '') === 'grouped' && empty($report['message']))
        <div class="bg-white dark:bg-[#0b1018] border rounded-3xl overflow-hidden">
            <table class="min-w-full text-sm">
                <thead class="border-b text-orange-600">
                <tr>
                    <th class="px-3 py-2 text-left">Дата</th>
                    <th>отм</th>
                    <th>Маршрут</th>
                    <th>Смена</th>
                    <th>Начало</th>
                    <th>Окончание</th>
                </tr>
                </thead>
                <tbody>
                @foreach($report['groups'] as $g)
                    <tr class="border-t bg-orange-50/40"><td colspan="6" class="px-3 py-1 font-medium">{{ $g['tab'] }} {{ $g['name'] }}</td></tr>
                    @foreach($g['rows'] as $r)
                        <tr class="border-t">
                            <td class="px-3 py-1">{{ $r['date'] }}</td>
                            <td>{{ $r['mark'] }}</td>
                            <td>{{ $r['work'] }}</td>
                            <td>{{ $r['shift'] }}</td>
                            <td>{{ $r['start'] }}</td>
                            <td>{{ $r['end'] }}</td>
                        </tr>
                    @endforeach
                @endforeach
                </tbody>
            </table>
        </div>
    @elseif(($report['layout'] ?? '') === 'table' && empty($report['message']))
        <div class="bg-white dark:bg-[#0b1018] border rounded-3xl overflow-auto">
            <table class="min-w-full text-sm">
                <thead class="border-b text-orange-600">
                <tr>
                    @foreach($report['columns'] as $col)
                        <th class="px-3 py-2 text-left">{{ $col }}</th>
                    @endforeach
                </tr>
                </thead>
                <tbody>
                @foreach($report['rows'] as $row)
                    <tr class="border-t">
                        @foreach($report['keys'] as $key)
                            <td class="px-3 py-1">{{ $row[$key] }}</td>
                        @endforeach
                    </tr>
                @endforeach
                @if(!empty($report['footer']))
                    @foreach($report['footer'] as $row)
                        <tr class="border-t font-semibold">
                            @foreach($report['keys'] as $key)
                                <td class="px-3 py-1">{{ $row[$key] ?? '' }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                @endif
                </tbody>
            </table>
        </div>
        @if(!empty($report['footer_text']))
            <p class="mt-3 text-sm">{{ $report['footer_text'] }}</p>
        @endif
    @endif
@endsection
