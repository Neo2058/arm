<div class="max-w-6xl">
    <h2 class="text-2xl font-bold mb-1">Картотека машинистов</h2>
    <p class="text-sm text-orange-700 dark:text-orange-200 mb-4">
        Как <code>LKM</code>: табельный, должность, класс, бригада, приём/увольнение. Всего: <strong>{{ $total }}</strong>.
        Импорт: <code>php artisan arm:import-dbf --only=personnel</code>.
    </p>

    <form class="flex flex-wrap gap-3 items-end mb-4 text-sm" onsubmit="event.preventDefault(); const p = new URLSearchParams({q: document.getElementById('pe-q').value, active: document.getElementById('pe-active').checked ? '1' : ''}); window.Naryad.loadPartial('{{ route('naryad.partial.personnel') }}?' + p.toString());">
        <div class="flex-1 min-w-[180px]">
            <label class="block text-xs mb-1">Поиск (ФИО / таб. / бригада)</label>
            <input id="pe-q" value="{{ $q }}" class="w-full border rounded-2xl px-3 py-2">
        </div>
        <label class="flex items-center gap-2 text-xs pb-2">
            <input id="pe-active" type="checkbox" {{ $active ? 'checked' : '' }}> только работающие
        </label>
        <button type="submit" class="px-4 py-2 bg-orange-600 text-white rounded-2xl">Показать</button>
    </form>

    <div class="bg-white dark:bg-[#0b1018] border border-gray-200 dark:border-white/10 rounded-3xl overflow-x-auto">
        <table class="min-w-full text-xs">
            <thead class="border-b border-gray-100 dark:border-white/10 text-orange-600">
            <tr>
                <th class="px-3 py-2 text-left">Таб.</th>
                <th class="px-3 py-2 text-left">ФИО</th>
                <th class="px-3 py-2">Должн.</th>
                <th class="px-3 py-2">Кл.</th>
                <th class="px-3 py-2">Бр.</th>
                <th class="px-3 py-2">Приём</th>
                <th class="px-3 py-2">Увольнение</th>
                <th class="px-3 py-2">Телефон</th>
            </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-white/10">
            @forelse($people as $p)
                <tr class="{{ $p->isFired() ? 'opacity-50' : '' }}">
                    <td class="px-3 py-1.5 font-mono">{{ $p->tab_number }}</td>
                    <td class="px-3 py-1.5 font-medium">{{ $p->full_name }} @if($p->is_brigadier)<span class="text-[10px] text-orange-500">бр.</span>@endif</td>
                    <td class="px-3 py-1.5 text-center">{{ $p->position_code }}</td>
                    <td class="px-3 py-1.5 text-center">{{ $p->class_code }}</td>
                    <td class="px-3 py-1.5 text-center">{{ $p->brigade_code }}</td>
                    <td class="px-3 py-1.5">{{ $p->hired_on?->format('d.m.Y') }}</td>
                    <td class="px-3 py-1.5">{{ $p->fired_on?->format('d.m.Y') }}</td>
                    <td class="px-3 py-1.5">{{ $p->phone_primary }}</td>
                </tr>
            @empty
                <tr><td colspan="8" class="px-4 py-6 text-center text-orange-500">Пусто. Импортируйте LKM.DBF.</td></tr>
            @endforelse
            </tbody>
        </table>
        @if($total > 200)
            <div class="px-4 py-2 text-[11px] text-orange-400">Показаны первые 200 — уточните поиск.</div>
        @endif
    </div>
</div>
