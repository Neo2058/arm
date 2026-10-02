@extends('uchet.layouts.uchet')
@section('content')
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold">Доплаты и премия</h1>
            <p class="text-sm text-orange-600">TEHUCH: техучёба, медкомиссия, работа в выходной. SPPREM: процент премии за месяц.</p>
        </div>
        <form class="flex items-center gap-2">
            <input type="month" name="month" value="{{ $month }}" class="border rounded-2xl px-3 py-2" onchange="this.form.submit()">
        </form>
    </div>

    <div class="mb-4 text-sm">
        <span class="px-3 py-1 rounded-2xl {{ $period->isClosed() ? 'bg-red-100 text-red-700' : 'bg-emerald-100 text-emerald-700' }}">
            {{ $period->isClosed() ? 'Месяц закрыт' : 'Месяц открыт' }}
        </span>
    </div>

    @unless($period->isClosed())
        <div class="bg-white dark:bg-[#0b1018] border rounded-3xl p-4 mb-6 text-sm">
            <div class="font-semibold mb-3">Ввод по сотруднику</div>
            <div class="flex flex-wrap gap-3 items-end">
                <label class="block">
                    <div class="text-xs text-orange-500 mb-1">Сотрудник</div>
                    <select id="ex-user" class="border rounded-2xl px-3 py-2 min-w-[16rem]">
                        <option value="">— выбрать —</option>
                        @foreach($people as $p)
                            <option value="{{ $p->user_id }}" data-tab="{{ $p->tab_number }}">{{ $p->tab_number }} {{ $p->full_name }}</option>
                        @endforeach
                    </select>
                </label>
                <label>техучёба, ч <input id="ex-teh" type="number" step="0.01" class="border rounded-2xl px-2 py-1 w-24"></label>
                <label>медк., ч <input id="ex-med" type="number" step="0.01" class="border rounded-2xl px-2 py-1 w-24"></label>
                <label>вых. дни <input id="ex-nvih" type="number" class="border rounded-2xl px-2 py-1 w-16"></label>
                <label>вых. часы <input id="ex-nvihh" type="number" step="0.01" class="border rounded-2xl px-2 py-1 w-24"></label>
                <label>премия % <input id="ex-prem" type="number" step="0.01" class="border rounded-2xl px-2 py-1 w-24"></label>
                <button id="ex-save" class="px-4 py-2 bg-orange-600 text-white rounded-2xl">Сохранить</button>
            </div>
        </div>
    @endunless

    <h2 class="text-lg font-semibold mb-2">Доплаты TEHUCH</h2>
    <div class="bg-white dark:bg-[#0b1018] border rounded-3xl overflow-x-auto mb-8">
        <table class="min-w-full text-sm">
            <thead class="border-b text-orange-600">
            <tr>
                <th class="px-3 py-2 text-left">Таб.</th>
                <th class="px-3 py-2 text-left">ФИО</th>
                <th class="px-3 py-2 text-right">Тех.</th>
                <th class="px-3 py-2 text-right">Медк.</th>
                <th class="px-3 py-2 text-right">Авар.</th>
                <th class="px-3 py-2 text-right">Вых.дни</th>
                <th class="px-3 py-2 text-right">Вых.часы</th>
            </tr>
            </thead>
            <tbody class="divide-y">
            @forelse($extras as $row)
                <tr>
                    <td class="px-3 py-2 font-mono">{{ $row->tab_number }}</td>
                    <td class="px-3 py-2">{{ $row->full_name }}</td>
                    <td class="px-3 py-2 text-right">{{ $row->hours_tech }}</td>
                    <td class="px-3 py-2 text-right">{{ $row->hours_med }}</td>
                    <td class="px-3 py-2 text-right">{{ $row->hours_accident }}</td>
                    <td class="px-3 py-2 text-right">{{ $row->extra_days_off }}</td>
                    <td class="px-3 py-2 text-right">{{ $row->extra_hours_off }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-4 py-8 text-center text-orange-500">Нет доплат за месяц.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <h2 class="text-lg font-semibold mb-2">Премия SPPREM</h2>
    <div class="bg-white dark:bg-[#0b1018] border rounded-3xl overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead class="border-b text-orange-600">
            <tr>
                <th class="px-3 py-2 text-left">Таб.</th>
                <th class="px-3 py-2 text-left">Должн.</th>
                <th class="px-3 py-2 text-right">План %</th>
                <th class="px-3 py-2 text-right">Факт %</th>
                <th class="px-3 py-2 text-right">КТУ</th>
            </tr>
            </thead>
            <tbody class="divide-y">
            @forelse($premiums as $row)
                <tr>
                    <td class="px-3 py-2 font-mono">{{ $row->tab_number }}</td>
                    <td class="px-3 py-2">{{ $row->position_code }}</td>
                    <td class="px-3 py-2 text-right">{{ $row->percent_plan }}</td>
                    <td class="px-3 py-2 text-right">{{ $row->percent_fact }}</td>
                    <td class="px-3 py-2 text-right">{{ $row->ktu }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="px-4 py-8 text-center text-orange-500">Нет строк премии за месяц.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <script>
    const month = '{{ $month }}';
    const csrf = '{{ csrf_token() }}';
    document.getElementById('ex-save')?.addEventListener('click', async () => {
        const userId = document.getElementById('ex-user').value;
        if (!userId) { alert('Выберите сотрудника'); return; }
        const headers = {'Content-Type':'application/json','X-CSRF-TOKEN':csrf};
        const extraRes = await fetch('{{ url('/uchet/extras') }}/' + userId + '?month=' + month, {
            method:'PUT', headers, body: JSON.stringify({
                hours_tech: document.getElementById('ex-teh').value,
                hours_med: document.getElementById('ex-med').value,
                extra_days_off: document.getElementById('ex-nvih').value,
                extra_hours_off: document.getElementById('ex-nvihh').value,
            })
        });
        const extraData = await extraRes.json();
        if (!extraData.success) { alert(extraData.message || 'Ошибка доплат'); return; }
        const prem = document.getElementById('ex-prem').value;
        if (prem !== '') {
            const premRes = await fetch('{{ url('/uchet/premiums') }}/' + userId + '?month=' + month, {
                method:'PUT', headers, body: JSON.stringify({ percent_fact: prem, percent_plan: prem })
            });
            const premData = await premRes.json();
            if (!premData.success) { alert(premData.message || 'Ошибка премии'); return; }
        }
        location.reload();
    });
    </script>
@endsection
