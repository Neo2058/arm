<div>
    <h2 class="text-2xl font-bold mb-1">Календарь — сколько составов на день</h2>
    <p class="text-sm text-orange-700 dark:text-orange-200 mb-4">На каждый день месяца указывается, сколько составов должно работать. Зависит от выбранного типа графика (кол-во маршрутов × людей).</p>

    @php
        $start = \Carbon\Carbon::createFromFormat('Y-m', $month)->startOfMonth();
        $daysInMonth = $start->daysInMonth;
    @endphp

    <div class="bg-white dark:bg-[#0b1018] border border-gray-200 dark:border-white/10 rounded-3xl p-6">
        <div class="flex items-center justify-between mb-3">
            <div class="text-sm text-orange-600 dark:text-orange-400">Месяц: <strong class="text-orange-700 dark:text-orange-300">{{ $month }}</strong></div>
            <button id="calendar-save-btn" class="px-4 py-1.5 text-sm bg-orange-600 hover:bg-orange-700 text-white rounded-2xl font-semibold">Сохранить весь месяц</button>
        </div>

        <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-2 text-xs">
            @for($d=1; $d<=$daysInMonth; $d++)
                @php
                    $dateStr = $start->copy()->day($d)->format('Y-m-d');
                    $q = $quotas[$dateStr] ?? null;
                    $req = $q ? $q->required_crews : 0;
                    $selectedType = $q ? $q->schedule_type_id : null;
                @endphp
                <div class="border rounded-2xl p-2 bg-white/50 dark:bg-black/10" data-day="{{ $d }}" data-date="{{ $dateStr }}">
                    <div class="font-mono mb-1 font-semibold">{{ $d }}</div>
                    <div class="text-[10px] text-orange-600 dark:text-orange-300 mb-1">составов:
                        <input type="number" class="req-crews w-14 border rounded px-1 py-0.5 text-sm" value="{{ $req }}" min="0">
                    </div>
                    <div class="text-[10px] text-orange-600 dark:text-orange-300">тип:
                        <select class="sched-type text-xs border rounded w-full mt-0.5">
                            <option value="">—</option>
                            @foreach($scheduleTypes as $st)
                                <option value="{{ $st->id }}" {{ $selectedType == $st->id ? 'selected' : '' }}>{{ $st->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            @endfor
        </div>
    </div>

    <div class="mt-2 text-xs text-orange-600 dark:text-orange-300">Данные сохраняются батчем для всего месяца. Можно менять тип графика на день.</div>
</div>

<script>
    (function() {
        const saveBtn = document.getElementById('calendar-save-btn');
        if (!saveBtn) return;

        saveBtn.addEventListener('click', async () => {
            const days = [];
            document.querySelectorAll('[data-day]').forEach(el => {
                const date = el.dataset.date;
                const req = parseInt(el.querySelector('.req-crews')?.value) || 0;
                const typeId = el.querySelector('.sched-type')?.value || null;
                days.push({
                    date: date,
                    required_crews: req,
                    schedule_type_id: typeId ? parseInt(typeId) : null
                });
            });

            if (!days.length) return;

            const original = saveBtn.textContent;
            saveBtn.disabled = true;
            saveBtn.textContent = 'Сохраняем...';

            try {
                const res = await fetch('{{ route('naryad.calendar.save') }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({
                        month: '{{ $month }}',
                        days: days
                    })
                });

                const data = await res.json();
                if (data.success) {
                    const monthEl = document.getElementById('naryad-month');
                    const m = monthEl ? monthEl.value : '{{ $month }}';
                    window.Naryad.loadPartial('{{ route('naryad.partial.calendar') }}?month=' + m);
                } else {
                    alert('Ошибка сохранения календаря');
                    saveBtn.textContent = original;
                    saveBtn.disabled = false;
                }
            } catch (e) {
                console.error(e);
                alert('Сетевая ошибка');
                saveBtn.textContent = original;
                saveBtn.disabled = false;
            }
        });
    })();
</script>