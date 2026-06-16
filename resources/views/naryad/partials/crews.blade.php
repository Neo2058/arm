<div class="max-w-4xl">
    <h2 class="text-2xl font-bold mb-1">Справочник составов (т6 / т5)</h2>
    <p class="text-sm text-orange-700 dark:text-orange-200 mb-4">Добавляйте пары по табельным номерам. Пример: 270012 — 270011 (т6). Эти данные будут использоваться при комплектовании в сетке.</p>

    <div class="bg-white dark:bg-[#0b1018] border border-gray-200 dark:border-white/10 rounded-3xl p-6">
        <div class="text-sm mb-3 font-medium text-orange-600 dark:text-orange-400">Добавить новый состав</div>
        <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
            <input id="crew-m1" type="text" placeholder="270012" class="rounded-2xl border px-3 py-2 text-sm">
            <input id="crew-m2" type="text" placeholder="270011" class="rounded-2xl border px-3 py-2 text-sm">
            <select id="crew-type" class="rounded-2xl border px-3 py-2 text-sm">
                <option value="t6">т6</option>
                <option value="t5">т5</option>
            </select>
            <button id="crew-add-btn" class="rounded-2xl bg-orange-600 hover:bg-orange-700 text-white text-sm font-semibold">Добавить</button>
        </div>
        <div class="text-[10px] text-orange-600 dark:text-orange-300 mt-1">Метка (label) генерируется автоматически как "первый - второй".</div>
    </div>

    @if($crews->isNotEmpty())
    <div class="mt-6 bg-white dark:bg-[#0b1018] border border-gray-200 dark:border-white/10 rounded-3xl overflow-hidden">
        <div class="px-6 py-3 border-b border-gray-100 dark:border-white/10 text-sm font-medium text-orange-600 dark:text-orange-400">Существующие составы</div>
        <div class="divide-y divide-gray-100 dark:divide-white/10">
            @foreach($crews as $c)
            <div class="px-6 py-3 flex items-center gap-4 text-sm">
                <div class="font-mono flex-1">{{ $c->label }}</div>
                <span class="px-2 py-0.5 text-xs rounded-full {{ $c->type === 't6' ? 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300' : 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300' }}">
                    {{ strtoupper($c->type) }}
                </span>
                @if($c->notes)
                    <span class="text-xs text-orange-700 dark:text-orange-200 truncate max-w-[200px]" title="{{ $c->notes }}">{{ \Illuminate\Support\Str::limit($c->notes, 40) }}</span>
                @endif
                <span class="ml-auto text-[10px] {{ $c->is_active ? 'text-green-600' : 'text-orange-600 dark:text-orange-300' }}">
                    {{ $c->is_active ? 'активен' : 'неактивен' }}
                </span>
            </div>
            @endforeach
        </div>
    </div>
    @else
    <div class="mt-6 text-xs text-orange-600 dark:text-orange-300">Составов пока нет. Добавьте первый выше.</div>
    @endif
</div>

<script>
    // Инициализация формы добавления состава (выполняется благодаря улучшенному loadPartial)
    (function() {
        const btn = document.getElementById('crew-add-btn');
        if (!btn) return;

        btn.addEventListener('click', async () => {
            const m1 = document.getElementById('crew-m1').value.trim();
            const m2 = document.getElementById('crew-m2').value.trim();
            const type = document.getElementById('crew-type').value;

            if (!m1 || !m2) {
                alert('Укажите оба табельных номера');
                return;
            }

            const originalText = btn.textContent;
            btn.disabled = true;
            btn.textContent = 'Добавляем...';

            try {
                const res = await fetch('{{ route('naryad.crews.store') }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({
                        member1_tab: m1,
                        member2_tab: m2,
                        type: type,
                        notes: '' // можно позже добавить поле
                    })
                });

                const data = await res.json();
                if (data.success) {
                    // Перезагружаем только этот раздел
                    const monthEl = document.getElementById('naryad-month');
                    const monthVal = monthEl ? monthEl.value : '';
                    let url = '{{ route('naryad.partial.crews') }}';
                    if (monthVal) url += '?month=' + monthVal;
                    window.Naryad.loadPartial(url);
                } else {
                    alert('Ошибка: ' + (data.message || 'не удалось добавить'));
                    btn.textContent = originalText;
                    btn.disabled = false;
                }
            } catch (e) {
                console.error(e);
                alert('Сетевая ошибка');
                btn.textContent = originalText;
                btn.disabled = false;
            }
        });
    })();
</script>