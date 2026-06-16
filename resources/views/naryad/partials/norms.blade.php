<div class="max-w-4xl">
    <h2 class="text-2xl font-bold mb-1">Начальные условия</h2>
    <p class="text-sm text-orange-700 dark:text-orange-200 mb-4">Базовые нормы рабочего времени и минимального отдыха для overlay-проверок при планировании. Эти данные используются для дополнительных проверок пересечений и лимитов (помимо запланированных ранее).</p>

    <div class="bg-white dark:bg-[#0b1018] border border-gray-200 dark:border-white/10 rounded-3xl p-6">
        <div class="text-sm mb-3 font-medium text-orange-600 dark:text-orange-400">Базовые нормы (6/1 неделя)</div>
        <form id="norm-form" class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
            <div>
                <label class="block text-xs text-orange-700 dark:text-orange-200 mb-1">Рабочих часов в год</label>
                <input name="year_hours" type="number" value="{{ $norm->year_hours }}" class="w-full border rounded-2xl px-3 py-2" min="0">
            </div>
            <div>
                <label class="block text-xs text-orange-700 dark:text-orange-200 mb-1">Рабочих часов в месяц</label>
                <input name="month_hours" type="number" value="{{ $norm->month_hours }}" class="w-full border rounded-2xl px-3 py-2" min="0">
            </div>
            <div>
                <label class="block text-xs text-orange-700 dark:text-orange-200 mb-1">Рабочих часов в неделю (6/1)</label>
                <input name="week_hours" type="number" value="{{ $norm->week_hours }}" class="w-full border rounded-2xl px-3 py-2" min="0">
            </div>
            <div>
                <label class="block text-xs text-orange-700 dark:text-orange-200 mb-1">Часов отдыха между сменами</label>
                <input name="min_rest_hours" type="number" value="{{ $norm->min_rest_hours }}" class="w-full border rounded-2xl px-3 py-2" min="0">
            </div>
            <div class="md:col-span-2">
                <button type="button" id="norm-save-btn" class="px-5 py-2 bg-orange-600 hover:bg-orange-700 text-white rounded-2xl text-sm font-semibold">Сохранить нормы</button>
            </div>
        </form>
    </div>

    <div class="mt-6 bg-white dark:bg-[#0b1018] border border-gray-200 dark:border-white/10 rounded-3xl p-6">
        <div class="text-sm mb-3 font-medium text-orange-600 dark:text-orange-400">Дополнительные условия</div>
        <div class="flex gap-3 items-end text-sm mb-4">
            <div class="flex-1">
                <label class="block text-xs text-orange-700 dark:text-orange-200 mb-1">Название условия</label>
                <input id="extra-name" class="w-full border rounded-2xl px-3 py-2" placeholder="Макс. дней подряд">
            </div>
            <div>
                <label class="block text-xs text-orange-700 dark:text-orange-200 mb-1">Значение</label>
                <input id="extra-value" class="w-32 border rounded-2xl px-3 py-2" placeholder="5">
            </div>
            <div class="flex-1">
                <label class="block text-xs text-orange-700 dark:text-orange-200 mb-1">Описание</label>
                <input id="extra-desc" class="w-full border rounded-2xl px-3 py-2" placeholder="Не более 5 дней подряд">
            </div>
            <div>
                <button id="extra-add-btn" class="px-5 py-2 bg-orange-600 hover:bg-orange-700 text-white rounded-2xl text-sm font-semibold mt-5">Добавить</button>
            </div>
        </div>

        @if($extras->isNotEmpty())
        <div class="divide-y divide-gray-100 dark:divide-white/10 text-sm">
            @foreach($extras as $e)
            <div class="py-3 flex items-center gap-4 extra-row" data-id="{{ $e->id }}" data-name="{{ $e->name }}" data-value="{{ $e->value }}" data-desc="{{ $e->description ?? '' }}">
                <div class="flex-1"><strong>{{ $e->name }}</strong>: {{ $e->value }}</div>
                @if($e->description)
                    <div class="text-xs text-orange-600 dark:text-orange-300 flex-1">{{ $e->description }}</div>
                @endif
                <button type="button" class="edit-extra-btn text-[10px] px-2 py-0.5 border rounded hover:bg-gray-100 dark:hover:bg-white/10">Ред.</button>
                <button type="button" class="delete-extra-btn text-[10px] px-2 py-0.5 border rounded hover:bg-red-100 text-red-600 dark:hover:bg-red-900/30">Удал.</button>
            </div>
            @endforeach
        </div>
        @else
        <div class="text-xs text-orange-600 dark:text-orange-300">Дополнительных условий пока нет. Добавьте выше.</div>
        @endif
    </div>
</div>

<script>
    (function() {
        // Save main norms
        const normBtn = document.getElementById('norm-save-btn');
        if (normBtn) {
            normBtn.addEventListener('click', async () => {
                const form = document.getElementById('norm-form');
                const formData = new FormData(form);
                const payload = {};
                formData.forEach((v,k) => payload[k] = v);

                const original = normBtn.textContent;
                normBtn.disabled = true;
                normBtn.textContent = 'Сохраняем...';

                try {
                    const res = await fetch('{{ route('naryad.norms.update') }}', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': '{{ csrf_token() }}',
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: JSON.stringify(payload)
                    });
                    const data = await res.json();
                    if (data.success) {
                        const m = document.getElementById('naryad-month')?.value || '';
                        let url = '{{ route('naryad.partial.norms') }}';
                        if (m) url += '?month=' + m;
                        window.Naryad.loadPartial(url);
                    } else {
                        alert('Ошибка сохранения');
                        normBtn.textContent = original;
                        normBtn.disabled = false;
                    }
                } catch (e) {
                    alert('Сетевая ошибка');
                    normBtn.textContent = original;
                    normBtn.disabled = false;
                }
            });
        }

        // Add extra condition
        const extraBtn = document.getElementById('extra-add-btn');
        if (extraBtn) {
            extraBtn.addEventListener('click', async () => {
                const name = document.getElementById('extra-name').value.trim();
                const value = document.getElementById('extra-value').value.trim();
                const desc = document.getElementById('extra-desc').value.trim() || null;
                if (!name || !value) {
                    alert('Укажите название и значение');
                    return;
                }

                const original = extraBtn.textContent;
                extraBtn.disabled = true;
                extraBtn.textContent = 'Добавляем...';

                try {
                    const res = await fetch('{{ route('naryad.extra_conditions.store') }}', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': '{{ csrf_token() }}',
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: JSON.stringify({ name, value, description: desc })
                    });
                    const data = await res.json();
                    if (data.success) {
                        const m = document.getElementById('naryad-month')?.value || '';
                        let url = '{{ route('naryad.partial.norms') }}';
                        if (m) url += '?month=' + m;
                        window.Naryad.loadPartial(url);
                    } else {
                        alert('Ошибка');
                        extraBtn.textContent = original;
                        extraBtn.disabled = false;
                    }
                } catch (e) {
                    alert('Сетевая ошибка');
                    extraBtn.textContent = original;
                    extraBtn.disabled = false;
                }
            });
        }

        // Edit / delete extras (similar to other sections)
        document.querySelectorAll('.edit-extra-btn').forEach(b => {
            b.addEventListener('click', () => {
                const row = b.closest('.extra-row');
                if (!row) return;
                document.getElementById('extra-name').value = row.dataset.name || '';
                document.getElementById('extra-value').value = row.dataset.value || '';
                document.getElementById('extra-desc').value = row.dataset.desc || '';
                // For simplicity, on save it will create new; for edit we can enhance later or use same store for now
                alert('Для редактирования заполните форму выше и добавьте заново (или доработайте). Удалите старое если нужно.');
            });
        });

        document.querySelectorAll('.delete-extra-btn').forEach(b => {
            b.addEventListener('click', async () => {
                if (!confirm('Удалить условие?')) return;
                const row = b.closest('.extra-row');
                const id = row ? row.dataset.id : null;
                if (!id) return;
                try {
                    const res = await fetch('{{ url("/naryad/extra-conditions") }}/' + id, {
                        method: 'DELETE',
                        headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'X-Requested-With': 'XMLHttpRequest' }
                    });
                    const data = await res.json();
                    if (data.success) {
                        const m = document.getElementById('naryad-month')?.value || '';
                        let url = '{{ route('naryad.partial.norms') }}';
                        if (m) url += '?month=' + m;
                        window.Naryad.loadPartial(url);
                    }
                } catch (e) { alert('Ошибка'); }
            });
        });
    })();
</script>