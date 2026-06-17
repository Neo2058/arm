<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>Наряд — Планирование | ТЧ-15</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />
    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/app.css', 'resources/js/app.jsx'])
    @else
        {{-- Fallback (как в teaching layout) --}}
        <style>
            /* Минимальный Tailwind для работы в dev без сборки — используем те же переменные, что и основной layout */
            body { font-family: 'Instrument Sans', system-ui, sans-serif; }
        </style>
    @endif
    <script>
        // Простой helper для AJAX (используем fetch, axios тоже доступен после сборки)
        window.Naryad = {
            loadPartial: async function (url, targetId = 'naryad-main-pane') {
                const pane = document.getElementById(targetId);
                if (!pane) return;
                pane.innerHTML = '<div class="p-8 text-center text-orange-300">Загрузка...</div>';
                try {
                    const res = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                    if (!res.ok) {
                        const text = await res.text().catch(() => '');
                        console.error('Partial load failed', res.status, url, text.substring(0, 500));
                        throw new Error(`HTTP ${res.status}`);
                    }
                    const html = await res.text();
                    pane.innerHTML = html;

                    // Execute any <script> tags in the loaded partial (innerHTML doesn't auto-run them)
                    const scripts = pane.querySelectorAll('script');
                    scripts.forEach(oldScript => {
                        const newScript = document.createElement('script');
                        if (oldScript.src) {
                            newScript.src = oldScript.src;
                        } else {
                            newScript.textContent = oldScript.textContent;
                        }
                        // Append to head or body to execute, then remove to clean
                        (document.head || document.body).appendChild(newScript);
                        newScript.remove();
                        oldScript.remove(); // clean up the original
                    });

                    // После вставки можно переинициализировать любые интерактивные элементы
                    if (window.Naryad.afterPartialLoad) window.Naryad.afterPartialLoad(pane);
                } catch (e) {
                    let detail = '';
                    if (e.message) detail = ' ' + e.message;
                    pane.innerHTML = '<div class="p-6 text-red-500">Не удалось загрузить раздел. Попробуйте ещё раз.' + detail + '<br><small>Подробности в консоли браузера (F12)</small></div>';
                    console.error(e);
                }
            },
            // Будущие хуки для после-загрузки (selects, формы и т.д.)
            afterPartialLoad: null
        };
    </script>
</head>
<body class="bg-[#FDFDFC] dark:bg-[#0a0a0a] text-orange-800 dark:text-orange-200 min-h-screen">
    <div class="flex h-screen overflow-hidden">
        <!-- УНИКАЛЬНЫЙ САЙДБАР ДЛЯ НАРЯДЧИКА -->
        <aside class="w-72 bg-white dark:bg-[#0b1018] border-r border-gray-200 dark:border-white/10 flex flex-col flex-shrink-0">
            <div class="p-5 border-b border-gray-100 dark:border-white/10">
                <div class="flex items-center gap-3">
                    <img src="{{ asset('images/logo.jpg') }}" class="w-9 h-9 rounded" alt="logo">
                    <div>
                        <div class="font-bold text-xl tracking-tight">ТЧ-<span class="text-red-600">15</span></div>
                        <div class="text-[10px] text-orange-500 -mt-0.5">ПЛАНИРОВАНИЕ НАРЯДА</div>
                    </div>
                </div>
            </div>

            <div class="p-3 text-xs font-semibold text-orange-400 dark:text-orange-500 px-5 pt-4">СПРАВОЧНИКИ И СЕТКА</div>

            <nav class="px-2 space-y-0.5">
                @php
                    $nav = [
                        ['key' => 'setka', 'label' => 'Сетка', 'icon' => '📋', 'route' => 'naryad.partial.setka'],
                        ['key' => 'crews', 'label' => 'Составы (т6 / т5)', 'icon' => '👥', 'route' => 'naryad.partial.crews'],
                        ['key' => 'variants', 'label' => 'Варианты маршрутов', 'icon' => '🚂', 'route' => 'naryad.partial.variants'],
                        ['key' => 'calendar', 'label' => 'Календарь (кол-во составов)', 'icon' => '📅', 'route' => 'naryad.partial.calendar'],
                        ['key' => 'types', 'label' => 'Типы графиков', 'icon' => '📊', 'route' => 'naryad.partial.types'],
                        ['key' => 'deviations', 'label' => 'Отвлечения', 'icon' => '🚫', 'route' => 'naryad.partial.deviations'],
                        ['key' => 'norms', 'label' => 'Начальные условия', 'icon' => '⏱️', 'route' => 'naryad.partial.norms'],
                        ['key' => 'users', 'label' => 'Пользователи (планирование)', 'icon' => '👤', 'route' => 'naryad.partial.users'],
                    ];
                    $current = request()->routeIs('naryad.index') ? 'setka' : (explode('.', request()->route()->getName())[2] ?? 'setka');
                @endphp

                @foreach($nav as $item)
                    <a href="#"
                       data-key="{{ $item['key'] }}"
                       data-url="{{ route($item['route']) }}"
                       class="naryad-nav-item flex items-center gap-3 px-4 py-2.5 rounded-2xl text-sm font-medium transition
                              {{ ($current ?? 'setka') === $item['key'] ? 'bg-orange-500/10 text-orange-600 dark:text-orange-400' : 'hover:bg-gray-100 dark:hover:bg-white/5 text-orange-600 dark:text-orange-300' }}">
                        <span class="text-lg w-5">{{ $item['icon'] }}</span>
                        <span>{{ $item['label'] }}</span>
                    </a>
                @endforeach
            </nav>

            <div class="mt-auto p-4 border-t border-gray-100 dark:border-white/10 text-xs">
                <a href="{{ route('podstroiki.index') }}" class="block mb-2 px-4 py-2 rounded-2xl hover:bg-gray-100 dark:hover:bg-white/5 text-orange-500 dark:text-orange-400">
                    → Подстройки (заявки)
                </a>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="w-full text-left px-4 py-2 rounded-2xl hover:bg-red-500/10 text-red-600 dark:text-red-400 text-sm">
                        Выйти
                    </button>
                </form>
                <div class="mt-3 text-[10px] text-orange-300 px-1">
                    Нарядчик: {{ $user->name ?? '—' }}<br>
                    Месяц: <span id="current-month-label">{{ $currentMonth ?? date('Y-m') }}</span>
                </div>
            </div>
        </aside>

        <!-- ОСНОВНАЯ ОБЛАСТЬ -->
        <div class="flex-1 flex flex-col overflow-hidden">
            <!-- Top bar -->
            <div class="h-14 border-b border-gray-200 dark:border-white/10 bg-white/80 dark:bg-[#0b1018]/80 backdrop-blur flex items-center px-6 justify-between flex-shrink-0">
                <div class="flex items-center gap-4">
                    <div class="text-lg font-semibold text-orange-600 dark:text-orange-400">Проектирование наряда на текущий день</div>
                    <div class="text-xs px-2 py-0.5 bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300 rounded">Только для нарядчика</div>
                </div>

                <div class="flex items-center gap-3">
                    <!-- Селектор месяца (влияет на все partials) -->
                    <form class="flex items-center gap-2" onsubmit="event.preventDefault(); const m = this.month.value; window.Naryad.loadPartial('{{ route('naryad.partial.setka') }}?month=' + m); window.Naryad.setActive('setka');">
                        <label class="text-xs text-orange-600 dark:text-orange-400">Месяц</label>
                        <input type="month" id="naryad-month" name="month" value="{{ $currentMonth ?? date('Y-m') }}"
                               class="text-sm border border-gray-300 dark:border-white/20 rounded-xl px-2 py-1 bg-white dark:bg-[#0b1018]"
                               onchange="const m=this.value; window.Naryad.loadPartial('{{ route('naryad.partial.setka') }}?month='+m); document.getElementById('current-month-label').textContent = m;">
                    </form>

                    <div class="text-xs text-orange-600 dark:text-orange-400">Данные из WorkShift / RoutesCatalog</div>
                </div>
            </div>

            <!-- Динамический контент (загружается AJAX) -->
            <div id="naryad-main-pane" class="flex-1 overflow-auto p-6 bg-[#FDFDFC] dark:bg-[#0a0a0a]">
                @yield('naryad-content', 'Загрузка сетки...')
            </div>
        </div>
    </div>

    <!-- Лёгкий баг-репорт (как в основном layout) -->
    <div class="fixed bottom-4 right-4 z-50">
        <button onclick="document.getElementById('naryad-bug').classList.toggle('hidden')"
                class="px-4 py-2 text-xs rounded-full bg-orange-500/10 border border-orange-500/30 text-orange-600 hover:bg-orange-500/20">
            Нашли баг?
        </button>
    </div>
    <div id="naryad-bug" class="hidden fixed inset-0 bg-black/60 z-[60] flex items-center justify-center p-4" onclick="this.classList.add('hidden')">
        <div class="bg-[#0b1018] w-full max-w-md rounded-3xl p-6 border border-white/10" onclick="event.stopImmediatePropagation()">
            <h3 class="text-orange-400 font-bold mb-2">Сообщить о проблеме в планировании наряда</h3>
            <form id="naryad-bug-form">
                <textarea name="description" required class="w-full h-28 bg-black/40 border border-white/10 rounded-2xl p-3 text-sm text-white" placeholder="Что не так в сетке / справочниках..."></textarea>
                <button type="submit" class="mt-3 w-full py-2 bg-orange-600 text-white rounded-2xl text-sm">Отправить</button>
            </form>
        </div>
    </div>

    <script>
        // Инициализация: при первой загрузке страницы сразу тянем "Сетку"
        document.addEventListener('DOMContentLoaded', function () {
            const pane = document.getElementById('naryad-main-pane');
            const initialUrl = '{{ route('naryad.partial.setka') }}?month=' + (document.getElementById('naryad-month')?.value || '{{ $currentMonth ?? date('Y-m') }}');
            // Загружаем начальный контент через loadPartial (чтобы hook и delegation работали)
            window.Naryad.loadPartial(initialUrl);

            // Event delegation для динамического контента (после AJAX partial loads)
            pane.addEventListener('click', function(e) {
                // Сетка: удаление назначения по × (должно быть раньше, чтобы stopPropagation сработал)
                const delBtn = e.target.closest('.delete-assign-btn');
                if (delBtn) {
                    e.stopPropagation();
                    e.preventDefault();
                    const td = delBtn.closest('td[data-user-id][data-date]');
                    if (td) {
                        const userId = td.dataset.userId;
                        const dateStr = td.dataset.date;
                        unassignNaryadShift(userId, dateStr, td);
                    }
                    return;
                }

                // Сетка: клик по ячейке назначения маршрута (редактирование)
                const assignCell = e.target.closest('td[data-user-id][data-date]');
                if (assignCell && !assignCell.querySelector('select') && !e.target.closest('.delete-assign-btn')) {
                    const userId = assignCell.dataset.userId;
                    const dateStr = assignCell.dataset.date;
                    // Берём текст из .assign-value если есть, иначе весь
                    let valEl = assignCell.querySelector('.assign-value');
                    let currentRoute = (valEl ? valEl.textContent : assignCell.textContent).trim();
                    if (currentRoute === '—' || currentRoute === '') currentRoute = '';
                    showNaryadAssignSelect(assignCell, userId, dateStr, currentRoute);
                }

                // Пользователи: клик по кнопке сохранения флагов
                const saveBtn = e.target.closest('.save-flags-btn');
                if (saveBtn) {
                    e.preventDefault();
                    handleSaveUserFlags(saveBtn);
                }
            });

            // Обработчик баг-формы (упрощённый)
            const bugForm = document.getElementById('naryad-bug-form');
            if (bugForm) {
                bugForm.onsubmit = async (e) => {
                    e.preventDefault();
                    alert('Спасибо! Репорт отправлен (заглушка). В реальном проекте — через /api/bug-report.');
                    document.getElementById('naryad-bug').classList.add('hidden');
                    bugForm.reset();
                };
            }

            // Sidebar nav active state handling (AJAX navigation)
            const nav = document.querySelector('aside nav');
            if (nav) {
                nav.addEventListener('click', function(e) {
                    const link = e.target.closest('.naryad-nav-item');
                    if (!link) return;

                    e.preventDefault();

                    const key = link.dataset.key;
                    const baseUrl = link.dataset.url;
                    const month = document.getElementById('naryad-month')?.value || '';
                    const fullUrl = baseUrl + (month ? '?month=' + month : '');

                    window.Naryad.loadPartial(fullUrl);
                    window.Naryad.setActive(key);
                });
            }

            // Initial active state is handled by server-rendered classes in the Blade template.
            // JS only takes over for subsequent AJAX tab switches.
        });

        // === Sidebar active tab management ===
        window.Naryad.setActive = function(key) {
            document.querySelectorAll('.naryad-nav-item').forEach(link => {
                if (link.dataset.key === key) {
                    link.classList.remove('hover:bg-gray-100', 'dark:hover:bg-white/5', 'text-orange-700', 'dark:text-orange-200');
                    link.classList.add('bg-orange-500/10', 'text-orange-600', 'dark:text-orange-400');
                } else {
                    link.classList.remove('bg-orange-500/10', 'text-orange-600', 'dark:text-orange-400');
                    link.classList.add('hover:bg-gray-100', 'dark:hover:bg-white/5', 'text-orange-700', 'dark:text-orange-200');
                }
            });
        };

        // === Интерактивная логика для Сетки и Пользователей (делегирование, чтобы работало после innerHTML) ===
        function showNaryadAssignSelect(cellEl, userId, dateStr, currentRoute) {
            let routes = window.NARYAD_ROUTES || [];
            if (!routes.length) {
                const cache = document.getElementById('naryad-routes-cache');
                if (cache && cache.dataset.routes) {
                    try {
                        routes = JSON.parse(cache.dataset.routes);
                        window.NARYAD_ROUTES = routes;
                    } catch(e) {}
                }
            }

            // Include variants (effective routes for different contexts), filtered by day's graph type if set
            let allOptions = routes.map(r => ({ value: r, label: r }));
            let dayGraph = null;
            const gCache = document.getElementById('naryad-daily-graphs-cache');
            if (gCache && gCache.dataset.graphs) {
                try {
                    const gs = JSON.parse(gCache.dataset.graphs);
                    dayGraph = gs[dateStr] || null;
                } catch(e) {}
            }

            const vCache = document.getElementById('naryad-variants-cache');
            if (vCache && vCache.dataset.variants) {
                try {
                    const vs = JSON.parse(vCache.dataset.variants);
                    vs.forEach(v => {
                        const vGraph = v.schedule_type_name || null;
                        const matches = !dayGraph || !vGraph || vGraph === dayGraph;
                        if (matches) {
                            allOptions.push({ value: v.effective, label: v.label });
                        }
                    });
                } catch(e) {}
            }

            // Include deviations (отвлечения) for planning grid
            const dCache = document.getElementById('naryad-deviations-cache');
            if (dCache && dCache.dataset.deviations) {
                try {
                    const ds = JSON.parse(dCache.dataset.deviations);
                    ds.forEach(d => {
                        allOptions.push({ value: d.value, label: d.label });
                    });
                } catch(e) {}
            }

            const originalHTML = cellEl.innerHTML;

            // Показать остаток часов на неделю пользователя (для понимания при назначении смен подряд)
            let weekInfoHtml = '';
            try {
                const normEl = document.getElementById('naryad-norm-cache');
                const weekLimit = normEl && normEl.dataset.weekLimit ? parseFloat(normEl.dataset.weekLimit) : 40;

                const hoursCacheEl = document.getElementById('naryad-user-hours-cache');
                if (hoursCacheEl && hoursCacheEl.dataset.hours) {
                    const data = JSON.parse(hoursCacheEl.dataset.hours);
                    const weekData = (data.week || {})[userId] || {};

                    // Вычисляем понедельник недели для dateStr (как в PHP startOfWeek MONDAY)
                    const d = new Date(dateStr + 'T00:00:00');
                    const jsDay = d.getDay(); // 0=вс ... 6=сб
                    const diff = (jsDay === 0 ? -6 : 1 - jsDay);
                    const monDate = new Date(d);
                    monDate.setDate(d.getDate() + diff);
                    const wkey = monDate.toISOString().slice(0, 10);

                    const planned = weekData[wkey] || 0;
                    const remaining = Math.max(0, Math.round((weekLimit - planned) * 10) / 10);
                    weekInfoHtml = `<div style="font-size:7px;color:#f59e0b;margin-bottom:1px;white-space:nowrap;">н:${planned}/${weekLimit} ост:${remaining}</div>`;
                }
            } catch (e) {
                // тихо игнорируем, чтобы не ломать выбор
            }

            let html = weekInfoHtml + '<select style="width:100%;font-size:11px;padding:1px 2px;border-radius:4px;border:1px solid #f59e0b;background:#fffbe6;color:#1f2937;">';
            html += '<option value="">—</option>';
            allOptions.forEach(opt => {
                const sel = (opt.value == currentRoute) ? 'selected' : '';
                html += `<option value="${opt.value}" ${sel}>${opt.label}</option>`;
            });
            html += '</select>';

            cellEl.innerHTML = html;

            const sel = cellEl.querySelector('select');
            if (sel) sel.focus();

            const save = (val) => {
                if (!val) {
                    // Пустое значение = удаление назначения
                    cellEl.innerHTML = '<span style="color:#f59e0b;font-size:10px;">удал...</span>';
                    unassignNaryadShift(userId, dateStr, cellEl, originalHTML);
                    return;
                }
                cellEl.innerHTML = '<span style="color:#f59e0b;font-size:10px;">сохр...</span>';

                fetch('{{ route('naryad.assign') }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({
                        user_id: userId,
                        plan_date: dateStr,
                        route_number: val
                    })
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        const monthEl = document.getElementById('naryad-month');
                        const monthVal = monthEl ? monthEl.value : '';
                        const url = '{{ route('naryad.partial.setka') }}?month=' + (monthVal || '');
                        window.Naryad.loadPartial(url);
                    } else {
                        alert('Ошибка: ' + (data.message || 'не удалось сохранить'));
                        cellEl.innerHTML = originalHTML;
                    }
                })
                .catch(e => {
                    console.error(e);
                    alert('Сетевая ошибка');
                    cellEl.innerHTML = originalHTML;
                });
            };

            if (sel) {
                sel.onchange = () => save(sel.value);
                sel.onblur = () => {
                    setTimeout(() => {
                        if (cellEl.querySelector('select')) cellEl.innerHTML = originalHTML;
                    }, 150);
                };
            }
        }

        // === Удаление назначения (для кнопки × и выбора пустого в селекте) ===
        function unassignNaryadShift(userId, dateStr, cellEl, originalHTML = null) {
            fetch('{{ route('naryad.unassign') }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({
                    user_id: userId,
                    plan_date: dateStr
                })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    const monthEl = document.getElementById('naryad-month');
                    const monthVal = monthEl ? monthEl.value : '';
                    const url = '{{ route('naryad.partial.setka') }}?month=' + (monthVal || '');
                    window.Naryad.loadPartial(url);
                } else {
                    alert('Ошибка: ' + (data.message || 'не удалось удалить'));
                    if (cellEl && originalHTML) cellEl.innerHTML = originalHTML;
                }
            })
            .catch(e => {
                console.error(e);
                alert('Сетевая ошибка при удалении');
                if (cellEl && originalHTML) cellEl.innerHTML = originalHTML;
            });
        }

        async function handleSaveUserFlags(btn) {
            const tr = btn.closest('tr');
            if (!tr) return;

            const profileId = btn.dataset.profileId || tr.dataset.profileId;
            if (!profileId) {
                alert('Нет профиля для сохранения');
                return;
            }

            const brigadir  = tr.querySelector('.flag-brigadir')?.checked ?? false;
            const t6        = tr.querySelector('.flag-t6')?.checked ?? false;
            const maneuvers = tr.querySelector('.flag-maneuvers')?.checked ?? false;
            const pomoshnik = tr.querySelector('.flag-pomoshnik')?.checked ?? false;
            const notes     = tr.querySelector('.flag-notes')?.value ?? '';

            const originalText = btn.textContent;
            btn.disabled = true;
            btn.textContent = 'Сохр...';

            try {
                const res = await fetch(`/naryad/user-profile/${profileId}/flags`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({
                        is_brigadir: brigadir,
                        can_manage_t6: t6,
                        can_maneuvers: maneuvers,
                        is_pomoshnik: pomoshnik,
                        additional_notes: notes
                    })
                });

                const data = await res.json();

                if (data.success) {
                    const monthEl = document.getElementById('naryad-month');
                    const monthVal = monthEl ? monthEl.value : '';
                    let usersUrl = '{{ route('naryad.partial.users') }}';
                    if (monthVal) usersUrl += '?month=' + monthVal;
                    window.Naryad.loadPartial(usersUrl);

                    let setkaUrl = '{{ route('naryad.partial.setka') }}';
                    if (monthVal) setkaUrl += '?month=' + monthVal;
                    setTimeout(() => {
                        window.Naryad.loadPartial(setkaUrl);
                    }, 120);
                } else {
                    alert('Ошибка: ' + (data.message || 'не удалось сохранить'));
                    btn.textContent = originalText;
                    btn.disabled = false;
                }
            } catch (e) {
                console.error(e);
                alert('Ошибка сети при сохранении флагов');
                btn.textContent = originalText;
                btn.disabled = false;
            }
        }
    </script>
</body>
</html>
