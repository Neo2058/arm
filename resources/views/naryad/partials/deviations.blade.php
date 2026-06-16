<div class="max-w-4xl">
    <h2 class="text-2xl font-bold mb-1">Отвлечения</h2>
    <p class="text-sm text-orange-700 dark:text-orange-200 mb-4">Справочник отвлечений (больничный, обучение, медкомиссия и т.д.). Данные используются при планировании в сетке (вместо маршрута можно назначить отвлечение).</p>

    <div class="bg-white dark:bg-[#0b1018] border border-gray-200 dark:border-white/10 rounded-3xl p-6">
        <div class="text-sm mb-3 font-medium text-orange-600 dark:text-orange-400">Добавить / редактировать отвлечение</div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-sm">
            <div>
                <label class="block text-xs text-orange-700 dark:text-orange-200 mb-1">Название</label>
                <input id="dev-name" class="w-full border rounded-2xl px-3 py-2" placeholder="Больничный">
            </div>
            <div>
                <label class="block text-xs text-orange-700 dark:text-orange-200 mb-1">Системный ключ (опц.)</label>
                <input id="dev-syskey" class="w-full border rounded-2xl px-3 py-2" placeholder="sick_leave">
            </div>
            <div>
                <label class="block text-xs text-orange-700 dark:text-orange-200 mb-1">Ставка в час</label>
                <input id="dev-rate" type="number" step="0.01" class="w-full border rounded-2xl px-3 py-2" value="250" min="0">
            </div>
            <div>
                <label class="block text-xs text-orange-700 dark:text-orange-200 mb-1">Минут по умолчанию</label>
                <input id="dev-minutes" type="number" class="w-full border rounded-2xl px-3 py-2" value="480" min="0">
            </div>
        </div>
        <div class="mt-3">
            <button id="dev-add-btn" class="px-5 py-2 bg-orange-600 hover:bg-orange-700 text-white rounded-2xl text-sm font-semibold">Добавить</button>
            <button id="dev-cancel-edit" class="hidden ml-2 px-3 py-2 text-sm border rounded-2xl" type="button">Отмена</button>
        </div>
    </div>

    @if($deviations->isNotEmpty())
    <div class="mt-6 bg-white dark:bg-[#0b1018] border border-gray-200 dark:border-white/10 rounded-3xl overflow-hidden">
        <div class="px-6 py-3 border-b border-gray-100 dark:border-white/10 text-sm font-medium text-orange-600 dark:text-orange-400">Существующие отвлечения</div>
        <div class="divide-y divide-gray-100 dark:divide-white/10 text-sm">
            @foreach($deviations as $d)
            <div class="px-6 py-3 flex items-center gap-4 deviation-row" 
                 data-id="{{ $d->id }}"
                 data-name="{{ $d->name }}"
                 data-syskey="{{ $d->sys_key ?? '' }}"
                 data-rate="{{ $d->hourly_rate }}"
                 data-minutes="{{ $d->default_minutes }}">
                <div class="flex-1 font-medium">{{ $d->name }}</div>
                <div class="text-xs text-orange-700 dark:text-orange-200">{{ $d->hourly_rate }} руб/ч × {{ $d->default_minutes }} мин</div>
                @if($d->sys_key)
                    <span class="text-[10px] px-2 py-0.5 bg-gray-100 text-orange-700 dark:bg-gray-800 dark:text-orange-300 rounded">{{ $d->sys_key }}</span>
                @endif
                <button type="button" class="edit-dev-btn text-[10px] px-2 py-0.5 border rounded hover:bg-gray-100 dark:hover:bg-white/10">Ред.</button>
                <button type="button" class="delete-dev-btn text-[10px] px-2 py-0.5 border rounded hover:bg-red-100 text-red-600 dark:hover:bg-red-900/30">Удал.</button>
            </div>
            @endforeach
        </div>
    </div>
    @endif
</div>

<script>
    (function() {
        const btn = document.getElementById('dev-add-btn');
        const cancelBtn = document.getElementById('dev-cancel-edit');
        if (!btn) return;

        let editingId = null;

        const resetForm = () => {
            document.getElementById('dev-name').value = '';
            document.getElementById('dev-syskey').value = '';
            document.getElementById('dev-rate').value = '250';
            document.getElementById('dev-minutes').value = '480';
            btn.textContent = 'Добавить';
            cancelBtn.classList.add('hidden');
            editingId = null;
        };

        btn.addEventListener('click', async () => {
            const name = document.getElementById('dev-name').value.trim();
            const sysKey = document.getElementById('dev-syskey').value.trim() || null;
            const rate = parseFloat(document.getElementById('dev-rate').value) || 0;
            const minutes = parseInt(document.getElementById('dev-minutes').value) || 0;

            if (!name) {
                alert('Укажите название отвлечения');
                return;
            }

            const original = btn.textContent;
            btn.disabled = true;
            btn.textContent = editingId ? 'Обновляем...' : 'Добавляем...';

            const url = editingId 
                ? '{{ url("/naryad/deviations") }}/' + editingId 
                : '{{ route('naryad.deviations.store') }}';
            const method = editingId ? 'PUT' : 'POST';

            try {
                const res = await fetch(url, {
                    method: method,
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({
                        name: name,
                        sys_key: sysKey,
                        hourly_rate: rate,
                        default_minutes: minutes
                    })
                });

                const data = await res.json();
                if (data.success) {
                    const monthEl = document.getElementById('naryad-month');
                    const m = monthEl ? monthEl.value : '';
                    let reloadUrl = '{{ route('naryad.partial.deviations') }}';
                    if (m) reloadUrl += '?month=' + m;
                    window.Naryad.loadPartial(reloadUrl);
                } else {
                    alert('Ошибка: ' + (data.message || 'не удалось сохранить'));
                    btn.textContent = original;
                    btn.disabled = false;
                }
            } catch (e) {
                console.error(e);
                alert('Сетевая ошибка');
                btn.textContent = original;
                btn.disabled = false;
            }
        });

        cancelBtn.addEventListener('click', resetForm);

        // Edit buttons
        document.querySelectorAll('.edit-dev-btn').forEach(b => {
            b.addEventListener('click', () => {
                const row = b.closest('.deviation-row');
                if (!row) return;
                editingId = row.dataset.id;
                document.getElementById('dev-name').value = row.dataset.name || '';
                document.getElementById('dev-syskey').value = row.dataset.syskey || '';
                document.getElementById('dev-rate').value = row.dataset.rate || '0';
                document.getElementById('dev-minutes').value = row.dataset.minutes || '0';
                btn.textContent = 'Обновить';
                cancelBtn.classList.remove('hidden');
                document.getElementById('dev-name').focus();
            });
        });

        // Delete buttons
        document.querySelectorAll('.delete-dev-btn').forEach(b => {
            b.addEventListener('click', async () => {
                if (!confirm('Удалить это отвлечение?')) return;
                const row = b.closest('.deviation-row');
                const id = row ? row.dataset.id : null;
                if (!id) return;

                try {
                    const res = await fetch('{{ url("/naryad/deviations") }}/' + id, {
                        method: 'DELETE',
                        headers: {
                            'X-CSRF-TOKEN': '{{ csrf_token() }}',
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    });
                    const data = await res.json();
                    if (data.success) {
                        const monthEl = document.getElementById('naryad-month');
                        const m = monthEl ? monthEl.value : '';
                        let url = '{{ route('naryad.partial.deviations') }}';
                        if (m) url += '?month=' + m;
                        window.Naryad.loadPartial(url);
                    } else {
                        alert('Не удалось удалить');
                    }
                } catch (e) {
                    alert('Ошибка удаления');
                }
            });
        });
    })();
</script>