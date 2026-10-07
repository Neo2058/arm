@extends('uchet.layouts.uchet')
@section('content')
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold">Архив учётных карточек</h1>
            <p class="text-sm text-orange-600">Снимок нарядов на момент закрытия ЛС (KOP1/KOP2).</p>
        </div>
        <form><input type="month" name="month" value="{{ $month }}" class="border rounded-2xl px-3 py-2" onchange="this.form.submit()"></form>
    </div>

    <div class="bg-white dark:bg-[#0b1018] border rounded-3xl overflow-hidden">
        <table class="min-w-full text-sm">
            <thead class="border-b text-orange-600">
            <tr>
                <th class="px-4 py-2 text-left">Таб.</th>
                <th class="px-4 py-2 text-left">ФИО</th>
                <th class="px-4 py-2 text-right">Дней</th>
                <th class="px-4 py-2 text-right">Линия</th>
                <th class="px-4 py-2 text-right">2 лицо</th>
                <th class="px-4 py-2 text-right">Ночь</th>
                <th></th>
            </tr>
            </thead>
            <tbody class="divide-y">
            @forelse($cards as $c)
                <tr>
                    <td class="px-4 py-2 font-mono">{{ $c['tab'] }}</td>
                    <td class="px-4 py-2">{{ $c['name'] }}</td>
                    <td class="px-4 py-2 text-right">{{ $c['days'] }}</td>
                    <td class="px-4 py-2 text-right">{{ $c['hours'] }}</td>
                    <td class="px-4 py-2 text-right">{{ $c['hours_2'] }}</td>
                    <td class="px-4 py-2 text-right">{{ $c['night'] }}</td>
                    <td class="px-4 py-2"><a class="text-orange-600" href="{{ route('uchet.archive.person', ['userId' => $c['user_id'], 'month' => $month]) }}">дни</a></td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-4 py-8 text-center text-orange-500">Копий нет. Закройте месяц — снимок УК запишется.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
@endsection
