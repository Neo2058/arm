@extends('uchet.layouts.uchet')
@section('content')
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold">Отчёты {{ $month }}</h1>
        <form><input type="month" name="month" value="{{ $month }}" class="border rounded-2xl px-3 py-2" onchange="this.form.submit()"></form>
    </div>

    <h2 class="font-semibold mb-2">Затраты времени</h2>
    <div class="bg-white dark:bg-[#0b1018] border rounded-3xl overflow-hidden mb-8">
        <table class="min-w-full text-sm">
            <thead class="border-b text-orange-600"><tr>
                <th class="px-3 py-2 text-left">Таб.</th><th class="text-left">ФИО</th>
                <th class="text-right px-3">Линия</th><th class="text-right px-3">2 лицо</th>
                <th class="text-right px-3">Ночь</th><th class="text-right px-3">Вечер</th><th class="text-right px-3">Праздник</th>
            </tr></thead>
            <tbody>
            @foreach($hours as $h)
                <tr class="border-t">
                    <td class="px-3 py-1 font-mono">{{ $h['tab'] }}</td>
                    <td>{{ $h['name'] }}</td>
                    <td class="text-right px-3">{{ $h['line'] }}</td>
                    <td class="text-right px-3">{{ $h['line_2'] }}</td>
                    <td class="text-right px-3">{{ $h['night'] }}</td>
                    <td class="text-right px-3">{{ $h['evening'] }}</td>
                    <td class="text-right px-3">{{ $h['holiday'] }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>

    <h2 class="font-semibold mb-2">Нетрудоспособность / отвлечения</h2>
    <div class="bg-white dark:bg-[#0b1018] border rounded-3xl overflow-hidden">
        <table class="min-w-full text-sm">
            <thead class="border-b text-orange-600"><tr>
                <th class="px-3 py-2 text-left">Таб.</th><th class="text-left">ФИО</th><th>Вид</th><th>С</th><th>По</th>
            </tr></thead>
            <tbody>
            @forelse($absences as $a)
                <tr class="border-t">
                    <td class="px-3 py-1 font-mono">{{ $a->tab_number }}</td>
                    <td>{{ $a->user?->name }}</td>
                    <td>{{ $a->kind_code }}</td>
                    <td>{{ $a->starts_on->format('d.m.Y') }}</td>
                    <td>{{ $a->ends_on->format('d.m.Y') }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="px-3 py-4 text-orange-500">Нет периодов в этом месяце.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
@endsection
