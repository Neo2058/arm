<div class="max-w-6xl">
    <h2 class="text-2xl font-bold mb-1">Разбивки смен</h2>
    <p class="text-sm text-orange-700 dark:text-orange-200 mb-4">
        Справочник как <code>RAZBSM</code>: график + маршрут + смена → линия, резерв, ночь, вечер, разрыв.
        При назначении в сетке эти часы пишутся в наряд. Всего записей: <strong>{{ $total }}</strong>.
        Импорт: <code>php artisan arm:import-dbf</code>.
    </p>

    <form class="flex flex-wrap gap-3 items-end mb-4 text-sm" onsubmit="event.preventDefault(); const p = new URLSearchParams({graph: document.getElementById('bd-graph').value, route: document.getElementById('bd-route').value, shift: document.getElementById('bd-shift').value}); window.Naryad.loadPartial('{{ route('naryad.partial.breakdowns') }}?' + p.toString());">
        <div>
            <label class="block text-xs mb-1">График</label>
            <input id="bd-graph" value="{{ $filters['graph'] }}" class="border rounded-2xl px-3 py-2 w-24" placeholder="код">
        </div>
        <div>
            <label class="block text-xs mb-1">Маршрут</label>
            <input id="bd-route" value="{{ $filters['route'] }}" class="border rounded-2xl px-3 py-2 w-24" placeholder="NM">
        </div>
        <div>
            <label class="block text-xs mb-1">Смена</label>
            <input id="bd-shift" value="{{ $filters['shift'] }}" class="border rounded-2xl px-3 py-2 w-20" placeholder="SM">
        </div>
        <button type="submit" class="px-4 py-2 bg-orange-600 text-white rounded-2xl text-sm">Показать</button>
    </form>

    @if($graphs->isNotEmpty())
        <div class="text-[11px] text-orange-500 mb-3">
            Коды графиков:
            @foreach($graphs as $g)
                <span class="mr-2"><strong>{{ $g->foxpro_code }}</strong> {{ $g->name }}</span>
            @endforeach
        </div>
    @endif

    <div class="bg-white dark:bg-[#0b1018] border border-gray-200 dark:border-white/10 rounded-3xl overflow-x-auto">
        <table class="min-w-full text-xs">
            <thead class="border-b border-gray-100 dark:border-white/10 text-orange-600">
            <tr>
                <th class="px-3 py-2 text-left">Гр</th>
                <th class="px-3 py-2 text-left">Мрш</th>
                <th class="px-3 py-2 text-left">См</th>
                <th class="px-3 py-2 text-left">№</th>
                <th class="px-3 py-2 text-left">Дол</th>
                <th class="px-3 py-2 text-right">Нач</th>
                <th class="px-3 py-2 text-right">Кон</th>
                <th class="px-3 py-2 text-right">Час</th>
                <th class="px-3 py-2 text-right">Лин</th>
                <th class="px-3 py-2 text-right">Рез</th>
                <th class="px-3 py-2 text-right">Ночь</th>
                <th class="px-3 py-2 text-right">Веч</th>
                <th class="px-3 py-2 text-right">Разр</th>
                <th class="px-3 py-2"></th>
            </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-white/10">
            @forelse($breakdowns as $b)
                <tr class="bd-row" data-id="{{ $b->id }}">
                    <td class="px-3 py-1.5">{{ $b->graph_code }}</td>
                    <td class="px-3 py-1.5 font-medium">{{ $b->route_code }}</td>
                    <td class="px-3 py-1.5">{{ $b->shift_code }}</td>
                    <td class="px-3 py-1.5">{{ $b->sequence }}</td>
                    <td class="px-3 py-1.5">{{ $b->position_code }}</td>
                    <td class="px-3 py-1.5 text-right"><input class="bd-start w-16 text-right bg-transparent border-b border-transparent focus:border-orange-400" value="{{ $b->start_hours }}"></td>
                    <td class="px-3 py-1.5 text-right"><input class="bd-end w-16 text-right bg-transparent border-b border-transparent focus:border-orange-400" value="{{ $b->end_hours }}"></td>
                    <td class="px-3 py-1.5 text-right"><input class="bd-total w-16 text-right bg-transparent border-b border-transparent focus:border-orange-400" value="{{ $b->hours_total }}"></td>
                    <td class="px-3 py-1.5 text-right"><input class="bd-line w-16 text-right bg-transparent border-b border-transparent focus:border-orange-400" value="{{ $b->hours_line }}"></td>
                    <td class="px-3 py-1.5 text-right"><input class="bd-reserve w-16 text-right bg-transparent border-b border-transparent focus:border-orange-400" value="{{ $b->hours_reserve }}"></td>
                    <td class="px-3 py-1.5 text-right"><input class="bd-night w-16 text-right bg-transparent border-b border-transparent focus:border-orange-400" value="{{ $b->hours_night }}"></td>
                    <td class="px-3 py-1.5 text-right"><input class="bd-evening w-16 text-right bg-transparent border-b border-transparent focus:border-orange-400" value="{{ $b->hours_evening }}"></td>
                    <td class="px-3 py-1.5 text-right"><input class="bd-break w-16 text-right bg-transparent border-b border-transparent focus:border-orange-400" value="{{ $b->hours_break }}"></td>
                    <td class="px-3 py-1.5"><button type="button" class="bd-save text-[10px] px-2 py-0.5 border rounded hover:bg-orange-50">Сохр.</button></td>
                </tr>
            @empty
                <tr><td colspan="14" class="px-4 py-6 text-center text-orange-500">Пусто. Сначала импортируйте RAZBSM.DBF.</td></tr>
            @endforelse
            </tbody>
        </table>
        @if($total > 150)
            <div class="px-4 py-2 text-[11px] text-orange-400">Показаны первые 150 строк — уточните фильтр.</div>
        @endif
    </div>
</div>

<script>
(function () {
    document.querySelectorAll('.bd-save').forEach(btn => {
        btn.addEventListener('click', async () => {
            const row = btn.closest('.bd-row');
            const id = row.dataset.id;
            btn.disabled = true;
            try {
                const res = await fetch('{{ url('/naryad/breakdowns') }}/' + id, {
                    method: 'PUT',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({
                        start_hours: row.querySelector('.bd-start').value,
                        end_hours: row.querySelector('.bd-end').value,
                        hours_total: row.querySelector('.bd-total').value,
                        hours_line: row.querySelector('.bd-line').value,
                        hours_reserve: row.querySelector('.bd-reserve').value,
                        hours_night: row.querySelector('.bd-night').value,
                        hours_evening: row.querySelector('.bd-evening').value,
                        hours_break: row.querySelector('.bd-break').value,
                    })
                });
                const data = await res.json();
                if (!data.success) alert(data.message || 'Ошибка');
            } catch (e) {
                alert('Не удалось сохранить');
            }
            btn.disabled = false;
        });
    });
})();
</script>
