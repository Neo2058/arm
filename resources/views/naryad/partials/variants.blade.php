<div class="max-w-5xl">
    <h2 class="text-2xl font-bold mb-1">Варианты маршрутов (проверка)</h2>
    <p class="text-sm text-orange-600 dark:text-orange-400 mb-4">Некоторые маршруты в ночь — одни, утром — другие (пример: 24 ночью, 25 или 36 утром). Здесь нарядчик определяет, каким маршрутом будет считаться тот или иной в конкретном контексте.</p>

    <div class="bg-white dark:bg-[#0b1018] border border-gray-200 dark:border-white/10 rounded-3xl p-6">
        <div class="text-sm mb-3 font-medium text-orange-600 dark:text-orange-400">Добавить / редактировать вариант</div>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-3 text-sm">
            <div>
                <label class="block text-xs text-orange-600 dark:text-orange-400 mb-1">Эффективный номер (что ставить в наряде)</label>
                <input id="var-effective" class="w-full border rounded-2xl px-3 py-2" placeholder="25">
            </div>
            <div>
                <label class="block text-xs text-orange-600 dark:text-orange-400 mb-1">Контекст</label>
                <select id="var-context" class="w-full border rounded-2xl px-3 py-2">
                    <option value="morning">Утро</option>
                    <option value="night">Ночь</option>
                    <option value="any">Любой</option>
                </select>
            </div>
            <div>
                <label class="block text-xs text-orange-600 dark:text-orange-400 mb-1">Базовый из каталога (опц.)</label>
                <select id="var-catalog" class="w-full border rounded-2xl px-3 py-2">
                    <option value="">—</option>
                    @foreach($routesCatalog as $r)
                        <option value="{{ $r->id }}">{{ $r->route_number }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs text-orange-600 dark:text-orange-400 mb-1">Тип графика (для фильтра в сетке)</label>
                <select id="var-schedule" class="w-full border rounded-2xl px-3 py-2">
                    <option value="">Любой / Общий</option>
                    @foreach($scheduleTypes as $st)
                        <option value="{{ $st->id }}">{{ $st->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs text-orange-600 dark:text-orange-400 mb-1">Время явки (override)</label>
                <input id="var-start-time" type="time" class="w-full border rounded-2xl px-3 py-2">
            </div>
            <div>
                <label class="block text-xs text-orange-600 dark:text-orange-400 mb-1">Время сдачи (override)</label>
                <input id="var-end-time" type="time" class="w-full border rounded-2xl px-3 py-2">
            </div>
            <div class="md:col-span-3">
                <label class="block text-xs text-orange-600 dark:text-orange-400 mb-1">Описание</label>
                <input id="var-desc" class="w-full border rounded-2xl px-3 py-2" placeholder="Ночь 24 = утро 25">
            </div>
        </div>
        <div class="mt-3">
            <button id="var-add-btn" type="button" class="px-5 py-2 bg-orange-600 hover:bg-orange-700 text-white rounded-2xl text-sm font-semibold">Добавить вариант</button>
            <button id="var-cancel-edit" class="hidden ml-2 px-3 py-2 text-sm border rounded-2xl" type="button">Отмена</button>
        </div>
    </div>

    @if($variants->isNotEmpty())
    <div class="mt-6 bg-white dark:bg-[#0b1018] border border-gray-200 dark:border-white/10 rounded-3xl overflow-hidden">
        <div class="px-6 py-3 border-b border-gray-100 dark:border-white/10 text-sm font-medium text-orange-600 dark:text-orange-400">Существующие варианты</div>
        <div class="divide-y divide-gray-100 dark:divide-white/10 text-sm">
            @foreach($variants as $v)
            <div class="px-6 py-3 flex flex-wrap items-center gap-x-4 gap-y-1 variant-row" 
                 data-id="{{ $v->id }}"
                 data-effective="{{ $v->effective_route }}"
                 data-context="{{ $v->context }}"
                 data-catalog-id="{{ $v->route_catalog_id ?? '' }}"
                 data-schedule-id="{{ $v->schedule_type_id ?? '' }}"
                 data-start-time="{{ $v->start_time ?? '' }}"
                 data-end-time="{{ $v->end_time ?? '' }}"
                 data-desc="{{ $v->description ?? '' }}">
                <div class="font-semibold">{{ $v->effective_route }}</div>
                <span class="px-2 py-0.5 text-xs rounded {{ $v->context === 'night' ? 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300' : ($v->context === 'morning' ? 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300' : 'bg-gray-100 text-orange-700') }}">
                    {{ $v->context === 'night' ? 'Ночь' : ($v->context === 'morning' ? 'Утро' : 'Любой') }}
                </span>
                @if($v->catalogRoute)
                    <span class="text-xs text-orange-600 dark:text-orange-400">из {{ $v->catalogRoute->route_number }}</span>
                @endif
                @if($v->scheduleType)
                    <span class="text-xs px-1.5 py-0.5 rounded bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300">{{ $v->scheduleType->name }}</span>
                @endif
                @if($v->start_time || $v->end_time)
                    <span class="text-xs text-orange-600 dark:text-orange-400">{{ $v->start_time }} → {{ $v->end_time }}</span>
                @endif
                @if($v->description)
                    <span class="text-xs text-orange-600 dark:text-orange-400 truncate max-w-xs">{{ $v->description }}</span>
                @endif
                <div class="ml-auto flex gap-2">
                    <button type="button" class="edit-variant-btn text-[10px] px-2 py-0.5 border rounded hover:bg-gray-100 dark:hover:bg-white/10">Ред.</button>
                    <button type="button" class="delete-variant-btn text-[10px] px-2 py-0.5 border rounded hover:bg-red-100 text-red-600 dark:hover:bg-red-900/30">Удал.</button>
                </div>
            </div>
            @endforeach
        </div>
    </div>
    @endif
</div>

<script>
    (function() {
        const addBtn = document.getElementById('var-add-btn');
        const cancelBtn = document.getElementById('var-cancel-edit');
        if (!addBtn) return;

        let editingId = null;

        const resetForm = () => {
            document.getElementById('var-effective').value = '';
            document.getElementById('var-context').value = 'morning';
            document.getElementById('var-catalog').value = '';
            document.getElementById('var-schedule').value = '';
            document.getElementById('var-start-time').value = '';
            document.getElementById('var-end-time').value = '';
            document.getElementById('var-desc').value = '';
            addBtn.textContent = 'Добавить вариант';
            cancelBtn.classList.add('hidden');
            editingId = null;
        };

        addBtn.addEventListener('click', async () => {
            const effective = document.getElementById('var-effective').value.trim();
            const context = document.getElementById('var-context').value;
            const catalogId = document.getElementById('var-catalog').value || null;
            const scheduleId = document.getElementById('var-schedule').value || null;
            const startTime = document.getElementById('var-start-time').value || null;
            const endTime = document.getElementById('var-end-time').value || null;
            const desc = document.getElementById('var-desc').value.trim() || null;

            if (!effective) {
                alert('Укажите эффективный номер');
                return;
            }

            const original = addBtn.textContent;
            addBtn.disabled = true;
            addBtn.textContent = editingId ? 'Обновляем...' : 'Добавляем...';

            const url = editingId 
                ? '{{ url("/naryad/variants") }}/' + editingId 
                : '{{ route('naryad.variants.store') }}';
            let fetchMethod = editingId ? 'PUT' : 'POST';

            try {
                const payload = {
                    effective_route: effective,
                    context: context,
                    route_catalog_id: catalogId ? parseInt(catalogId) : null,
                    schedule_type_id: scheduleId ? parseInt(scheduleId) : null,
                    start_time: startTime,
                    end_time: endTime,
                    description: desc,
                    is_active: true
                };

                const headers = {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'X-Requested-With': 'XMLHttpRequest'
                };

                // Use X-HTTP-Method-Override + POST for better compatibility with proxies/servers
                if (editingId) {
                    fetchMethod = 'POST';
                    headers['X-HTTP-Method-Override'] = 'PUT';
                }

                const res = await fetch(url, {
                    method: fetchMethod,
                    headers: headers,
                    body: JSON.stringify(payload)
                });

                const data = await res.json();
                if (data.success) {
                    const monthEl = document.getElementById('naryad-month');
                    const m = monthEl ? monthEl.value : '';
                    let reloadUrl = '{{ route('naryad.partial.variants') }}';
                    if (m) reloadUrl += '?month=' + m;
                    window.Naryad.loadPartial(reloadUrl);
                } else {
                    alert('Ошибка: ' + (data.message || 'не удалось сохранить'));
                    addBtn.textContent = original;
                    addBtn.disabled = false;
                }
            } catch (e) {
                console.error(e);
                alert('Сетевая ошибка');
                addBtn.textContent = original;
                addBtn.disabled = false;
            }
        });

        cancelBtn.addEventListener('click', resetForm);

        // Edit
        document.querySelectorAll('.edit-variant-btn').forEach(b => {
            b.addEventListener('click', () => {
                const row = b.closest('.variant-row');
                if (!row) return;
                editingId = row.dataset.id;
                document.getElementById('var-effective').value = row.dataset.effective || '';
                document.getElementById('var-context').value = row.dataset.context || 'any';
                document.getElementById('var-catalog').value = row.dataset.catalogId || '';
                document.getElementById('var-schedule').value = row.dataset.scheduleId || '';
                document.getElementById('var-start-time').value = row.dataset.startTime || '';
                document.getElementById('var-end-time').value = row.dataset.endTime || '';
                document.getElementById('var-desc').value = row.dataset.desc || '';
                addBtn.textContent = 'Обновить вариант';
                cancelBtn.classList.remove('hidden');
                document.getElementById('var-effective').focus();
            });
        });

        // Delete
        document.querySelectorAll('.delete-variant-btn').forEach(b => {
            b.addEventListener('click', async () => {
                if (!confirm('Удалить этот вариант?')) return;
                const row = b.closest('.variant-row');
                const id = row ? row.dataset.id : null;
                if (!id) return;

                try {
                    const res = await fetch('{{ url("/naryad/variants") }}/' + id, {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': '{{ csrf_token() }}',
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-HTTP-Method-Override': 'DELETE'
                        }
                    });
                    const data = await res.json();
                    if (data.success) {
                        const monthEl = document.getElementById('naryad-month');
                        const m = monthEl ? monthEl.value : '';
                        let url = '{{ route('naryad.partial.variants') }}';
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