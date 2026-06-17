<div class="max-w-xl">
    <h2 class="text-2xl font-bold mb-1">Типы графиков</h2>
    <p class="text-sm text-orange-700 dark:text-orange-200 mb-4">Определяют, сколько маршрутов и сколько людей (человек на маршрут) нужно на день при данном типе.</p>

    <div class="bg-white dark:bg-[#0b1018] border border-gray-200 dark:border-white/10 rounded-3xl p-6">
        <div class="text-sm mb-3 font-medium text-orange-600 dark:text-orange-400">Добавить новый тип</div>
        <div class="flex flex-wrap gap-3 items-end text-sm">
            <div class="flex-1 min-w-[180px]">
                <label class="block text-xs text-orange-700 dark:text-orange-200 mb-1">Название</label>
                <input id="type-name" class="w-full border rounded-2xl px-3 py-2" placeholder="Обычный, Праздничный...">
            </div>
            <div>
                <label class="block text-xs text-orange-700 dark:text-orange-200 mb-1">Маршрутов в сутки</label>
                <input id="type-routes" type="number" class="w-24 border rounded-2xl px-3 py-2" value="8" min="0">
            </div>
            <div>
                <label class="block text-xs text-orange-700 dark:text-orange-200 mb-1">Людей на маршрут</label>
                <input id="type-people" type="number" class="w-24 border rounded-2xl px-3 py-2" value="2" min="1">
            </div>
            <div>
                <label class="block text-xs text-orange-700 dark:text-orange-200 mb-1">&nbsp;</label>
                <button id="type-add-btn" type="button" class="px-5 py-2 bg-orange-600 hover:bg-orange-700 text-white rounded-2xl text-sm font-semibold">Добавить</button>
            </div>
        </div>
        <div class="text-[10px] text-orange-600 dark:text-orange-300 mt-2">Можно добавить описание позже через админку.</div>
    </div>

    @if($types->isNotEmpty())
    <div class="mt-6 bg-white dark:bg-[#0b1018] border border-gray-200 dark:border-white/10 rounded-3xl overflow-hidden">
        <div class="px-6 py-3 border-b border-gray-100 dark:border-white/10 text-sm font-medium text-orange-600 dark:text-orange-400">Существующие типы</div>
        <div class="divide-y divide-gray-100 dark:divide-white/10 text-sm">
            @foreach($types as $t)
            <div class="px-6 py-3 flex items-center gap-4 type-row" data-id="{{ $t->id }}" data-name="{{ $t->name }}" data-routes="{{ $t->routes_count }}" data-people="{{ $t->people_per_route }}">
                <div class="flex-1 font-medium">{{ $t->name }}</div>
                <div class="text-xs text-orange-700 dark:text-orange-200">{{ $t->routes_count }} маршрутов × {{ $t->people_per_route }} чел.</div>
                @if($t->is_default)
                    <span class="text-[10px] px-2 py-0.5 bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300 rounded">по умолчанию</span>
                @endif
                <button type="button" class="edit-type-btn text-[10px] px-2 py-0.5 border rounded hover:bg-gray-100 dark:hover:bg-white/10">Ред.</button>
                <button type="button" class="delete-type-btn text-[10px] px-2 py-0.5 border rounded hover:bg-red-100 text-red-600 dark:hover:bg-red-900/30">Удал.</button>
            </div>
            @endforeach
        </div>
    </div>
    @endif
</div>

<script>
    (function() {
        const btn = document.getElementById('type-add-btn');
        if (!btn) return;

        let editingId = null;

        const resetForm = () => {
            document.getElementById('type-name').value = '';
            document.getElementById('type-routes').value = '8';
            document.getElementById('type-people').value = '2';
            btn.textContent = 'Добавить';
            editingId = null;
        };

        btn.addEventListener('click', async () => {
            const name = document.getElementById('type-name').value.trim();
            const routes = parseInt(document.getElementById('type-routes').value) || 0;
            const people = parseInt(document.getElementById('type-people').value) || 1;

            if (!name) {
                alert('Укажите название типа');
                return;
            }

            const original = btn.textContent;
            btn.disabled = true;
            btn.textContent = editingId ? 'Обновляем...' : 'Добавляем...';

            const url = editingId 
                ? '{{ url("/naryad/types") }}/' + editingId 
                : '{{ route('naryad.types.store') }}';
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
                        routes_count: routes,
                        people_per_route: people
                    })
                });

                const data = await res.json();
                if (data.success) {
                    const monthEl = document.getElementById('naryad-month');
                    const m = monthEl ? monthEl.value : '';
                    let reloadUrl = '{{ route('naryad.partial.types') }}';
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

        // Edit buttons
        document.querySelectorAll('.edit-type-btn').forEach(b => {
            b.addEventListener('click', () => {
                const row = b.closest('.type-row');
                if (!row) return;
                editingId = row.dataset.id;
                document.getElementById('type-name').value = row.dataset.name || '';
                document.getElementById('type-routes').value = row.dataset.routes || '0';
                document.getElementById('type-people').value = row.dataset.people || '1';
                btn.textContent = 'Обновить';
                document.getElementById('type-name').focus();
            });
        });

        // Delete buttons
        document.querySelectorAll('.delete-type-btn').forEach(b => {
            b.addEventListener('click', async () => {
                if (!confirm('Удалить этот тип?')) return;
                const row = b.closest('.type-row');
                const id = row ? row.dataset.id : null;
                if (!id) return;

                try {
                    const res = await fetch('{{ url("/naryad/types") }}/' + id, {
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
                        let url = '{{ route('naryad.partial.types') }}';
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