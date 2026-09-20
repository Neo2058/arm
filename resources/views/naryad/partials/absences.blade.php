<div class="max-w-4xl">
    <h2 class="text-2xl font-bold mb-1">Отвлечения (периоды)</h2>
    <p class="text-sm text-orange-700 dark:text-orange-200 mb-4">
        Как <code>OTVM</code>: больничный, отпуск и прочие виды с датами с–по. Всего: <strong>{{ $total }}</strong>.
        Импорт: <code>php artisan arm:import-dbf --only=absences</code>.
    </p>

    <form class="flex flex-wrap gap-3 items-end mb-4 text-sm" onsubmit="event.preventDefault(); window.Naryad.loadPartial('{{ route('naryad.partial.absences') }}?tab=' + encodeURIComponent(document.getElementById('ab-tab').value));">
        <div>
            <label class="block text-xs mb-1">Табельный</label>
            <input id="ab-tab" value="{{ $tab }}" class="border rounded-2xl px-3 py-2 w-28">
        </div>
        <button type="submit" class="px-4 py-2 bg-orange-600 text-white rounded-2xl">Показать</button>
    </form>

    <div class="bg-white dark:bg-[#0b1018] border border-gray-200 dark:border-white/10 rounded-3xl p-6 mb-6">
        <div class="flex flex-wrap gap-3 items-end text-sm">
            <div><label class="block text-xs mb-1">Таб.</label><input id="ab-new-tab" class="border rounded-2xl px-3 py-2 w-24" value="{{ $tab }}"></div>
            <div><label class="block text-xs mb-1">С</label><input id="ab-new-from" type="date" class="border rounded-2xl px-3 py-2"></div>
            <div><label class="block text-xs mb-1">По</label><input id="ab-new-to" type="date" class="border rounded-2xl px-3 py-2"></div>
            <div><label class="block text-xs mb-1">Вид</label><input id="ab-new-kind" class="border rounded-2xl px-3 py-2 w-20" placeholder="Б / ОТ"></div>
            <button id="ab-add" type="button" class="px-4 py-2 bg-orange-600 text-white rounded-2xl">Добавить</button>
        </div>
    </div>

    <div class="bg-white dark:bg-[#0b1018] border border-gray-200 dark:border-white/10 rounded-3xl overflow-hidden">
        <div class="divide-y divide-gray-100 dark:divide-white/10 text-sm">
            @forelse($absences as $a)
                <div class="px-6 py-3 flex items-center gap-4">
                    <div class="w-16 font-mono">{{ $a->tab_number }}</div>
                    <div class="w-44">{{ $a->starts_on->format('d.m.Y') }} — {{ $a->ends_on->format('d.m.Y') }}</div>
                    <div class="w-12 font-medium">{{ $a->kind_code }}</div>
                    <div class="flex-1 text-orange-500">{{ $a->user?->name }}</div>
                    <button type="button" class="ab-del text-[10px] px-2 py-0.5 border rounded text-red-600" data-id="{{ $a->id }}">Удал.</button>
                </div>
            @empty
                <div class="px-6 py-6 text-orange-500">Нет периодов.</div>
            @endforelse
        </div>
    </div>
</div>
<script>
(function () {
    const reload = () => window.Naryad.loadPartial('{{ route('naryad.partial.absences') }}?tab=' + encodeURIComponent(document.getElementById('ab-tab').value));
    document.getElementById('ab-add')?.addEventListener('click', async () => {
        const res = await fetch('{{ route('naryad.absences.store') }}', {
            method: 'POST',
            headers: {'Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}','X-Requested-With':'XMLHttpRequest'},
            body: JSON.stringify({
                tab_number: document.getElementById('ab-new-tab').value,
                starts_on: document.getElementById('ab-new-from').value,
                ends_on: document.getElementById('ab-new-to').value,
                kind_code: document.getElementById('ab-new-kind').value
            })
        });
        const data = await res.json().catch(() => ({}));
        if (data.success) reload(); else alert('Не удалось сохранить');
    });
    document.querySelectorAll('.ab-del').forEach(btn => {
        btn.addEventListener('click', async () => {
            if (!confirm('Удалить период?')) return;
            const res = await fetch('{{ url('/naryad/absences') }}/' + btn.dataset.id, {
                method: 'DELETE',
                headers: {'X-CSRF-TOKEN':'{{ csrf_token() }}','X-Requested-With':'XMLHttpRequest'}
            });
            const data = await res.json().catch(() => ({}));
            if (data.success) reload();
        });
    });
})();
</script>
