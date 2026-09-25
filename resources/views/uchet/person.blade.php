@extends('uchet.layouts.uchet')
@section('content')
    <a href="{{ route('uchet.index', ['month' => $month]) }}" class="text-sm text-orange-600">← к карточкам</a>
    <h1 class="text-2xl font-bold mt-2 mb-1">{{ $personnel?->full_name ?? 'Сотрудник' }}</h1>
    <p class="text-sm text-orange-600 mb-4">Таб. {{ $personnel?->tab_number }} · {{ $month }}</p>

    <div class="bg-white dark:bg-[#0b1018] border rounded-3xl overflow-x-auto">
        <table class="min-w-full text-xs">
            <thead class="border-b text-orange-600">
            <tr>
                <th class="px-3 py-2">Дата</th>
                <th class="px-3 py-2">Назначение</th>
                <th class="px-3 py-2">Линия</th>
                <th class="px-3 py-2">2 лицо</th>
                <th class="px-3 py-2">Ночь</th>
                <th class="px-3 py-2">Резерв</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            @foreach($days as $d)
                <tr class="border-t uc-row" data-id="{{ $d->id }}">
                    <td class="px-3 py-1.5">{{ $d->plan_date->format('d.m') }}</td>
                    <td class="px-3 py-1.5">{{ $d->route_number }} @if($d->two_person)<span class="text-orange-500">2л</span>@endif</td>
                    <td class="px-3 py-1.5"><input class="h-line w-16 text-right bg-transparent border-b" value="{{ $d->hours_line }}" {{ $period->isClosed() ? 'disabled' : '' }}></td>
                    <td class="px-3 py-1.5"><input class="h-line2 w-16 text-right bg-transparent border-b" value="{{ $d->hours_line_2 }}" {{ $period->isClosed() ? 'disabled' : '' }}></td>
                    <td class="px-3 py-1.5"><input class="h-night w-16 text-right bg-transparent border-b" value="{{ $d->hours_night }}" {{ $period->isClosed() ? 'disabled' : '' }}></td>
                    <td class="px-3 py-1.5"><input class="h-res w-16 text-right bg-transparent border-b" value="{{ $d->hours_reserve }}" {{ $period->isClosed() ? 'disabled' : '' }}></td>
                    <td class="px-3 py-1.5">
                        @unless($period->isClosed())
                            <button type="button" class="uc-save text-[10px] border rounded px-2">Сохр.</button>
                        @endunless
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    <script>
    document.querySelectorAll('.uc-save').forEach(btn => {
        btn.addEventListener('click', async () => {
            const row = btn.closest('.uc-row');
            const res = await fetch('{{ url('/uchet/assignments') }}/' + row.dataset.id, {
                method: 'PUT',
                headers: {'Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}'},
                body: JSON.stringify({
                    hours_line: row.querySelector('.h-line').value,
                    hours_line_2: row.querySelector('.h-line2').value,
                    hours_night: row.querySelector('.h-night').value,
                    hours_reserve: row.querySelector('.h-res').value,
                })
            });
            const data = await res.json();
            if (!data.success) alert(data.message || 'Ошибка');
        });
    });
    </script>
@endsection
