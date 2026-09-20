<div class="max-w-xl">
    <h2 class="text-2xl font-bold mb-1">Праздничные дни</h2>
    <p class="text-sm text-orange-700 dark:text-orange-200 mb-4">
        Справочник как <code>PRAZD</code>. Нужен для праздничных часов в наряде и лицевом счёте.
        Импорт: <code>php artisan arm:import-dbf --only=holidays</code>.
    </p>

    <div class="bg-white dark:bg-[#0b1018] border border-gray-200 dark:border-white/10 rounded-3xl p-6 mb-6">
        <div class="flex flex-wrap gap-3 items-end text-sm">
            <div>
                <label class="block text-xs mb-1">Дата</label>
                <input id="hol-date" type="date" class="border rounded-2xl px-3 py-2">
            </div>
            <div class="flex-1 min-w-[160px]">
                <label class="block text-xs mb-1">Название</label>
                <input id="hol-name" class="w-full border rounded-2xl px-3 py-2" placeholder="Новый год">
            </div>
            <button id="hol-add" type="button" class="px-5 py-2 bg-orange-600 text-white rounded-2xl text-sm font-semibold">Добавить</button>
        </div>
    </div>

    <div class="bg-white dark:bg-[#0b1018] border border-gray-200 dark:border-white/10 rounded-3xl overflow-hidden">
        <div class="divide-y divide-gray-100 dark:divide-white/10 text-sm">
            @forelse($holidays as $h)
                <div class="px-6 py-3 flex items-center gap-4">
                    <div class="w-28 font-medium">{{ $h->holiday_date->format('d.m.Y') }}</div>
                    <div class="flex-1">{{ $h->name }}</div>
                    <button type="button" class="hol-del text-[10px] px-2 py-0.5 border rounded text-red-600" data-id="{{ $h->id }}">Удал.</button>
                </div>
            @empty
                <div class="px-6 py-6 text-orange-500">Праздников нет.</div>
            @endforelse
        </div>
    </div>
</div>

<script>
(function () {
    const reload = () => window.Naryad.loadPartial('{{ route('naryad.partial.holidays') }}');
    document.getElementById('hol-add')?.addEventListener('click', async () => {
        const holiday_date = document.getElementById('hol-date').value;
        const name = document.getElementById('hol-name').value.trim();
        if (!holiday_date) { alert('Укажите дату'); return; }
        const res = await fetch('{{ route('naryad.holidays.store') }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': '{{ csrf_token() }}',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({ holiday_date, name })
        });
        const data = await res.json().catch(() => ({}));
        if (data.success) reload();
        else alert(data.message || 'Не удалось добавить');
    });
    document.querySelectorAll('.hol-del').forEach(btn => {
        btn.addEventListener('click', async () => {
            if (!confirm('Удалить день?')) return;
            const res = await fetch('{{ url('/naryad/holidays') }}/' + btn.dataset.id, {
                method: 'DELETE',
                headers: {
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });
            const data = await res.json().catch(() => ({}));
            if (data.success) reload();
        });
    });
})();
</script>
