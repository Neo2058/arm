@extends('uchet.layouts.uchet')
@section('content')
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold">Регистрация маршрутных листов</h1>
            <p class="text-sm text-orange-600">Картотека на дату. Отметка — как OTBOR в NARJAD (F6).</p>
        </div>
        <form class="flex items-center gap-2">
            <input type="date" name="date" value="{{ $date }}" class="border rounded-2xl px-3 py-2" onchange="this.form.submit()">
            <select name="filter" class="border rounded-2xl px-3 py-2" onchange="this.form.submit()">
                <option value="all" @selected($filter === 'all')>вся картотека</option>
                <option value="mash" @selected($filter === 'mash')>машинисты</option>
                <option value="pom" @selected($filter === 'pom')>помощники</option>
            </select>
        </form>
    </div>

    <div class="mb-4 text-sm">
        <span class="px-3 py-1 rounded-2xl {{ $period->isClosed() ? 'bg-red-100 text-red-700' : 'bg-emerald-100 text-emerald-700' }}">
            {{ $period->isClosed() ? 'Месяц закрыт' : 'Месяц открыт' }}
        </span>
    </div>

    <div class="bg-white dark:bg-[#0b1018] border rounded-3xl overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead class="border-b text-orange-600">
            <tr>
                <th class="px-3 py-2 text-left">Таб.</th>
                <th class="px-3 py-2 text-left">ФИО</th>
                <th class="px-3 py-2">Дол</th>
                <th class="px-3 py-2">МЛ</th>
                <th class="px-3 py-2">Маршрут</th>
                <th class="px-3 py-2">Смена</th>
                <th class="px-3 py-2 text-right">Линия</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            @forelse($rows as $row)
                @php $a = $row['assignment']; @endphp
                <tr class="border-t sh-row" data-id="{{ $row['user_id'] }}">
                    <td class="px-3 py-1.5 font-mono">{{ $row['tab'] }}</td>
                    <td class="px-3 py-1.5">{{ $row['name'] }}</td>
                    <td class="px-3 py-1.5 text-center">{{ $row['position'] }}</td>
                    <td class="px-3 py-1.5 text-center">
                        <input type="checkbox" class="sh-reg" {{ $a?->registered ? 'checked' : '' }} {{ $period->isClosed() ? 'disabled' : '' }}>
                    </td>
                    <td class="px-3 py-1.5">
                        <input class="sh-work w-16 bg-transparent border-b" value="{{ $a?->work_code }}" {{ $period->isClosed() ? 'disabled' : '' }}>
                    </td>
                    <td class="px-3 py-1.5">
                        <input class="sh-shift w-12 bg-transparent border-b" value="{{ $a?->shift_code }}" {{ $period->isClosed() ? 'disabled' : '' }}>
                    </td>
                    <td class="px-3 py-1.5 text-right">{{ $a?->hours_line }}</td>
                    <td class="px-3 py-1.5">
                        @unless($period->isClosed())
                            <button type="button" class="sh-save text-[10px] border rounded px-2">Сохр.</button>
                        @endunless
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="px-4 py-8 text-center text-orange-500">Нет людей на эту дату.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <script>
    const date = '{{ $date }}';
    const csrf = '{{ csrf_token() }}';
    document.querySelectorAll('.sh-save').forEach(btn => {
        btn.addEventListener('click', async () => {
            const row = btn.closest('.sh-row');
            const res = await fetch('{{ url('/uchet/sheets') }}/' + row.dataset.id + '?date=' + date, {
                method: 'PUT',
                headers: {'Content-Type':'application/json','X-CSRF-TOKEN':csrf},
                body: JSON.stringify({
                    registered: row.querySelector('.sh-reg').checked,
                    work_code: row.querySelector('.sh-work').value,
                    shift_code: row.querySelector('.sh-shift').value,
                })
            });
            const data = await res.json();
            if (data.success) location.reload(); else alert(data.message || 'Ошибка');
        });
    });
    </script>
@endsection
