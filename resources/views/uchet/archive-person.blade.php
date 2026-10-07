@extends('uchet.layouts.uchet')
@section('content')
    <a href="{{ route('uchet.archive', ['month' => $month]) }}" class="text-sm text-orange-600">← к архиву</a>
    <h1 class="text-2xl font-bold mt-2 mb-1">{{ $personnel?->full_name ?? 'Сотрудник' }}</h1>
    <p class="text-sm text-orange-600 mb-4">Таб. {{ $personnel?->tab_number }} · копия УК {{ $month }}</p>

    <div class="bg-white dark:bg-[#0b1018] border rounded-3xl overflow-x-auto">
        <table class="min-w-full text-xs">
            <thead class="border-b text-orange-600">
            <tr>
                <th class="px-3 py-2">Дата</th>
                <th class="px-3 py-2">М</th>
                <th class="px-3 py-2">См</th>
                <th class="px-3 py-2">МЛ</th>
                <th class="px-3 py-2 text-right">Линия</th>
                <th class="px-3 py-2 text-right">2 лицо</th>
                <th class="px-3 py-2 text-right">Ночь</th>
                <th class="px-3 py-2 text-right">Резерв</th>
            </tr>
            </thead>
            <tbody>
            @forelse($days as $d)
                <tr class="border-t">
                    <td class="px-3 py-1.5">{{ $d->plan_date->format('d.m') }}</td>
                    <td class="px-3 py-1.5">{{ $d->work_code ?? $d->route_number }}</td>
                    <td class="px-3 py-1.5">{{ $d->shift_code }}</td>
                    <td class="px-3 py-1.5">{{ $d->registered ? '+' : '' }}</td>
                    <td class="px-3 py-1.5 text-right">{{ $d->hours_line }}</td>
                    <td class="px-3 py-1.5 text-right">{{ $d->hours_line_2 }}</td>
                    <td class="px-3 py-1.5 text-right">{{ $d->hours_night }}</td>
                    <td class="px-3 py-1.5 text-right">{{ $d->hours_reserve }}</td>
                </tr>
            @empty
                <tr><td colspan="8" class="px-4 py-8 text-center text-orange-500">Нет копии за этот месяц.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
@endsection
