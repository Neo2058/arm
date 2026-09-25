@extends('uchet.layouts.uchet')
@section('content')
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold">Учётные карточки</h1>
            <p class="text-sm text-orange-600">Часы из нарядов за месяц. 2 лица — отдельной колонкой.</p>
        </div>
        <form class="flex items-center gap-2">
            <input type="month" name="month" value="{{ $month }}" class="border rounded-2xl px-3 py-2" onchange="this.form.submit()">
        </form>
    </div>

    <div class="mb-4 flex flex-wrap gap-3 items-center text-sm">
        <span class="px-3 py-1 rounded-2xl {{ $period->isClosed() ? 'bg-red-100 text-red-700' : 'bg-emerald-100 text-emerald-700' }}">
            {{ $period->isClosed() ? 'Месяц закрыт' : 'Месяц открыт' }}
        </span>
        @unless($period->isClosed())
            <button id="gen-btn" class="px-4 py-2 bg-orange-600 text-white rounded-2xl">Собрать ЛС</button>
            <button id="close-btn" class="px-4 py-2 border rounded-2xl">Закрыть месяц</button>
        @else
            @if(auth()->user()->isDispatcher() || auth()->user()->isAdmin())
                <button id="reopen-btn" class="px-4 py-2 border rounded-2xl">Открыть месяц</button>
            @endif
        @endunless
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
                    <td class="px-4 py-2"><a class="text-orange-600" href="{{ route('uchet.person', ['userId' => $c['user_id'], 'month' => $month]) }}">дни</a></td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-4 py-8 text-center text-orange-500">Нет нарядов за месяц.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <script>
    const month = '{{ $month }}';
    const csrf = '{{ csrf_token() }}';
    const post = async (url) => {
        const res = await fetch(url, {method:'POST', headers:{'Content-Type':'application/json','X-CSRF-TOKEN':csrf}, body: JSON.stringify({month})});
        const data = await res.json();
        if (data.success) location.reload(); else alert(data.message || 'Ошибка');
    };
    document.getElementById('gen-btn')?.addEventListener('click', () => post('{{ route('uchet.generate') }}'));
    document.getElementById('close-btn')?.addEventListener('click', () => { if (confirm('Закрыть месяц? Наряды зафиксируются.')) post('{{ route('uchet.close') }}'); });
    document.getElementById('reopen-btn')?.addEventListener('click', () => post('{{ route('uchet.reopen') }}'));
    </script>
@endsection
