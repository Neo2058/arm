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
                        ['key' => 'breakdowns', 'label' => 'Разбивки смен', 'icon' => '⏱️', 'route' => 'naryad.partial.breakdowns'],
                        ['key' => 'holidays', 'label' => 'Праздники', 'icon' => '🎉', 'route' => 'naryad.partial.holidays'],
                        ['key' => 'deviations', 'label' => 'Отвлечения', 'icon' => '🚫', 'route' => 'naryad.partial.deviations'],
                        ['key' => 'norms', 'label' => 'Начальные условия', 'icon' => '⏱️', 'route' => 'naryad.partial.norms'],
                        ['key' => 'personnel', 'label' => 'Картотека', 'icon' => '📇', 'route' => 'naryad.partial.personnel'],
                        ['key' => 'appointments', 'label' => 'Назначения', 'icon' => '📌', 'route' => 'naryad.partial.appointments'],
                        ['key' => 'absences', 'label' => 'Отвлечения (периоды)', 'icon' => '🛌', 'route' => 'naryad.partial.absences'],
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
                <a href="{{ route('uchet.index') }}" class="block mb-2 px-4 py-2 rounded-2xl hover:bg-gray-100 dark:hover:bg-white/5 text-orange-500 dark:text-orange-400">
                    → Учёт / ЛС
                </a>
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

    <!-- Modal for rich route assignment -->
    <div id="naryad-assign-modal" class="hidden fixed inset-0 bg-black/60 z-[70] flex items-center justify-center p-4" onclick="this.classList.add('hidden')">
        <div class="bg-white dark:bg-[#0b1018] w-full max-w-3xl rounded-3xl p-6 border border-gray-200 dark:border-white/10 max-h-[85vh] overflow-auto" onclick="event.stopImmediatePropagation()">
            <div class="flex justify-between items-center mb-4">
                <div>
                    <div id="modal-title" class="font-semibold text-5xl"></div>
                    <div id="modal-subtitle" class="text-lg text-orange-500 dark:text-orange-400"></div>
                </div>
                <button onclick="document.getElementById('naryad-assign-modal').classList.add('hidden');" class="text-2xl leading-none text-orange-500 hover:text-orange-700">&times;</button>
            </div>
            <div id="modal-options" class="grid grid-cols-1 gap-2 text-3xl">
                <!-- JS populated rich options -->
            </div>
            <!-- clear option is added dynamically in JS with proper context -->
        </div>
    </div>

    <!-- Modal for podstroiki details and full approval (like /podstroiki) -->
    <div id="naryad-podstroika-modal" class="hidden fixed inset-0 bg-black/60 z-[70] flex items-center justify-center p-4" onclick="this.classList.add('hidden')">
        <div class="bg-white dark:bg-[#0b1018] w-full max-w-xl rounded-3xl p-6 border border-gray-200 dark:border-white/10 max-h-[85vh] overflow-auto" onclick="event.stopImmediatePropagation()">
            <div class="flex justify-between items-center mb-4">
                <div>
                    <div id="pod-modal-title" class="font-semibold text-3xl">Подстройка смены</div>
                    <div id="pod-modal-subtitle" class="text-lg text-orange-500 dark:text-orange-400"></div>
                </div>
                <button onclick="document.getElementById('naryad-podstroika-modal').classList.add('hidden');" class="text-2xl leading-none text-orange-500 hover:text-orange-700">&times;</button>
            </div>

            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-orange-600 dark:text-orange-400 mb-1">Описание подстройки</label>
                    <div id="pod-modal-details" class="p-4 bg-orange-50 dark:bg-white/5 rounded-2xl text-xl whitespace-pre-wrap min-h-[80px]"></div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-orange-600 dark:text-orange-400 mb-1">Статус согласования</label>
                    <select id="pod-modal-status" class="w-full border border-gray-300 dark:border-white/20 rounded-2xl px-4 py-3 text-xl bg-white dark:bg-[#0b1018]">
                        <option value="pending">В ожидании</option>
                        <option value="podstroeno">Подстроено</option>
                    </select>
                </div>
            </div>

            <div class="mt-6 flex gap-3">
                <button id="pod-modal-save-btn" class="flex-1 py-3 bg-orange-600 hover:bg-orange-700 text-white rounded-2xl text-xl font-medium">Сохранить статус</button>
                <button onclick="document.getElementById('naryad-podstroika-modal').classList.add('hidden');" class="px-8 py-3 border border-gray-300 dark:border-white/20 rounded-2xl text-xl">Закрыть</button>
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

                // Сетка: клик по ячейке назначения маршрута (модальное окно с деталями)
                const assignCell = e.target.closest('td[data-user-id][data-date]');
                if (assignCell && !e.target.closest('.delete-assign-btn')) {
                    const userId = assignCell.dataset.userId;
                    const dateStr = assignCell.dataset.date;
                    let valEl = assignCell.querySelector('.assign-value');
                    let currentRoute = (valEl ? valEl.textContent.trim() : assignCell.textContent.trim());
                    if (currentRoute === '—' || currentRoute === '') currentRoute = '';
                    showNaryadAssignModal(assignCell, userId, dateStr, currentRoute);
                }

                // Подстройки: клик по строке под фамилией для открытия модалки с полным функционалом
                const podClick = e.target.closest('.podstroiki-clickable');
                if (podClick) {
                    e.stopPropagation();
                    const podId = podClick.dataset.podId;
                    if (podId) {
                        showPodstroikaModal(podId);
                    }
                }

                // Сохранить лимит подстроек для пользователя
                const limitBtn = e.target.closest('.save-pod-limit-btn');
                if (limitBtn) {
                    e.stopPropagation();
                    const userId = limitBtn.dataset.userId;
                    const input = limitBtn.parentElement.querySelector('.pod-limit-input');
                    if (!input || !userId) return;
                    const max = parseInt(input.value) || 0;
                    const month = input.dataset.month || '';
                    limitBtn.disabled = true;
                    limitBtn.textContent = '...';
                    fetch('{{ route('naryad.podstroika-limit.save') }}', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'X-Requested-With': 'XMLHttpRequest'},
                        body: JSON.stringify({user_id: userId, for_month: month, max_approved: max})
                    }).then(r => r.json()).then(data => {
                        if (data.success) {
                            // перезагрузить для обновления
                            const m = document.getElementById('naryad-month')?.value || '';
                            window.Naryad.loadPartial('{{ route('naryad.partial.setka') }}?month=' + m);
                        }
                    }).finally(() => {
                        limitBtn.disabled = false;
                        limitBtn.textContent = '✓';
                    });
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
        // === Модальное окно выбора маршрута с подробностями (время, место, длительность) ===
        function showNaryadAssignModal(cellEl, userId, dateStr, currentRoute) {
            const modal = document.getElementById('naryad-assign-modal');
            const titleEl = document.getElementById('modal-title');
            const subEl = document.getElementById('modal-subtitle');
            const optsEl = document.getElementById('modal-options');

            titleEl.textContent = 'Выбор смены / маршрута';
            subEl.textContent = dateStr;

            optsEl.innerHTML = '<div class="p-4 text-center text-orange-400">Загрузка вариантов...</div>';

            // Сначала определяем тип графика дня (из квоты)
            let dayGraph = null;
            const gCache = document.getElementById('naryad-daily-graphs-cache');
            if (gCache && gCache.dataset.graphs) {
                try { const gs = JSON.parse(gCache.dataset.graphs); dayGraph = gs[dateStr] || null; } catch(e){}
            }

            // Чётность ночи для этой даты
            let nightParity = null;
            const pCache = document.getElementById('naryad-night-parities-cache');
            if (pCache && pCache.dataset.parities) {
                try {
                    const pars = JSON.parse(pCache.dataset.parities);
                    nightParity = pars[dateStr] || null;
                } catch(e){}
            }

            // Build rich options
            // Всегда берём свежие данные из кэша части (чтобы учитывать schedule_type_name и актуальные маршруты)
            let routes = [];
            const routesCache = document.getElementById('naryad-routes-cache');
            if (routesCache && routesCache.dataset.routes) {
                try { 
                    routes = JSON.parse(routesCache.dataset.routes); 
                    window.NARYAD_ROUTES = routes; 
                } catch(e){}
            } else if (window.NARYAD_ROUTES && window.NARYAD_ROUTES.length) {
                routes = window.NARYAD_ROUTES;
            }

            // Фильтруем маршруты из каталога по типу графика дня И чётности ночи
            const filteredRoutes = routes.filter(r => {
                const rGraph = r.schedule_type_name || null;
                const graphMatch = !dayGraph || !rGraph || rGraph === dayGraph;

                const rParity = r.night_parity || null;
                const parityMatch = !rParity || !nightParity || rParity === nightParity;

                return graphMatch && parityMatch;
            });

            let allOptions = filteredRoutes.map(r => ({ 
                value: r.value || r, 
                label: r.label || r,
                start_time: r.start_time || '',
                start_location: r.start_location || '',
                end_time: r.end_time || '',
                end_location: r.end_location || '',
                break_duration: r.break_duration || 0,
                from_night: r.from_night || null,
            }));

            const vCache = document.getElementById('naryad-variants-cache');
            if (vCache && vCache.dataset.variants) {
                try {
                    const vs = JSON.parse(vCache.dataset.variants);
                    vs.forEach(v => {
                        const vGraph = v.schedule_type_name || null;
                        const graphMatch = !dayGraph || !vGraph || vGraph === dayGraph;

                        const vParity = v.night_parity || null;
                        const parityMatch = !vParity || !nightParity || vParity === nightParity;

                        if (graphMatch && parityMatch) {
                            const vlabel = v.label || v.effective;
                            const vval = v.value || vlabel;
                            allOptions.push({ 
                                value: vval,   
                                label: vlabel,
                                start_time: v.start_time || '',
                                start_location: v.start_location || '',
                                end_time: v.end_time || '',
                                end_location: v.end_location || '',
                                break_duration: v.break_duration || 0,
                                from_night: v.from_night || null,
                            });
                        }
                    });
                } catch(e) {}
            }

            const dCache = document.getElementById('naryad-deviations-cache');
            if (dCache && dCache.dataset.deviations) {
                try {
                    const ds = JSON.parse(dCache.dataset.deviations);
                    ds.forEach(d => {
                        allOptions.push({ value: d.value, label: d.label, is_dev: true });
                    });
                } catch(e) {}
            }

            // compute duration for rich display (gross shift length from start to end, as shown in grid)
            allOptions.forEach(o => {
                if (!o.is_dev && o.start_time && o.end_time) {
                    try {
                        const [sh, sm] = o.start_time.split(':').map(Number);
                        const [eh, em] = o.end_time.split(':').map(Number);
                        let mins = (eh * 60 + em) - (sh * 60 + sm);
                        if (mins < 0) mins += 24 * 60;
                        o.duration = (mins / 60).toFixed(1) + 'ч';
                    } catch (e) {}
                }
            });

            // Фильтр уже назначенных на этот день (кроме текущей редактируемой ячейки),
            // чтобы было видно, какие смены/маршруты ещё свободны
            let usedRoutes = {};
            const usedCache = document.getElementById('naryad-daily-used-routes-cache');
            if (usedCache && usedCache.dataset.usedRoutes) {
                try {
                    const allUsed = JSON.parse(usedCache.dataset.usedRoutes);
                    usedRoutes = allUsed[dateStr] || {};
                } catch(e){}
            }
            const currentRouteStr = String(currentRoute || '');
            allOptions = allOptions.filter(opt => {
                if (opt.is_dev) return true; // отклонения (больничный и т.п.) оставляем
                const val = String(opt.value || '');
                const isCurrent = val === currentRouteStr;
                return isCurrent || !usedRoutes[val];
            });

            const originalHTML = cellEl.innerHTML;
            optsEl.innerHTML = '';

            allOptions.forEach(opt => {
                const isCurrent = String(opt.value) === String(currentRoute);
                const div = document.createElement('div');
                div.className = `p-4 border rounded-2xl cursor-pointer flex justify-between items-start gap-3 hover:bg-orange-50 dark:hover:bg-orange-950/30 ${isCurrent ? 'ring-2 ring-orange-500' : ''}`;

                let details = '';
                if (!opt.is_dev) {
                    const st = opt.start_time || '';
                    const sl = opt.start_location || '';
                    const et = opt.end_time || '';
                    const el = opt.end_location || '';
                    const dur = opt.duration || '';
                    details = `<div class="text-[30px] text-orange-600 dark:text-orange-400 mt-1">${st} ${sl} → ${et} ${el} ${dur ? '('+dur+')' : ''}</div>`;
                    if (opt.from_night) {
                        details += `<div class="text-[16px] text-teal-600 dark:text-teal-400">→ автоподстановка на след. день: ${opt.from_night}</div>`;
                    }
                }

                div.innerHTML = `
                    <div class="flex-1">
                        <div class="font-semibold text-3xl">${opt.label}</div>
                        ${details}
                    </div>
                    <div class="text-lg ${isCurrent ? 'text-orange-600' : 'text-orange-400'} self-center">выбрать</div>
                `;

                div.onclick = () => {
                    modal.classList.add('hidden');
                    saveAssignment(userId, dateStr, opt.value, cellEl, originalHTML);
                };
                optsEl.appendChild(div);
            });

            // Clear
            const clr = document.createElement('div');
            clr.className = 'mt-3 p-2 text-center text-red-500 hover:bg-red-50 dark:hover:bg-red-950/30 rounded-2xl cursor-pointer text-sm';
            clr.textContent = 'Очистить ячейку';
            clr.onclick = () => {
                modal.classList.add('hidden');
                saveAssignment(userId, dateStr, '', cellEl, originalHTML);
            };
            optsEl.appendChild(clr);

            modal.classList.remove('hidden');
        }

        function saveAssignment(userId, dateStr, val, cellEl, originalHTML) {
            if (!val) {
                cellEl.innerHTML = originalHTML;
                fetch('{{ route('naryad.unassign') }}', {
                    method: 'POST',
                    headers: {'Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}','X-Requested-With':'XMLHttpRequest'},
                    body: JSON.stringify({user_id: userId, plan_date: dateStr})
                }).then(() => {
                    const m = document.getElementById('naryad-month')?.value || '';
                    window.Naryad.loadPartial('{{ route('naryad.partial.setka') }}?month=' + m);
                });
                return;
            }

            cellEl.innerHTML = '<span style="color:#f59e0b;font-size:10px;">сохр...</span>';

            fetch('{{ route('naryad.assign') }}', {
                method: 'POST',
                headers: {'Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}','X-Requested-With':'XMLHttpRequest'},
                body: JSON.stringify({user_id: userId, plan_date: dateStr, route_number: val})
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    const m = document.getElementById('naryad-month')?.value || '';
                    window.Naryad.loadPartial('{{ route('naryad.partial.setka') }}?month=' + m);
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
        }

        // backward compat
        window.showNaryadAssignSelect = showNaryadAssignModal;

        // === Модальное окно для подстройки (полный просмотр + согласование как в /podstroiki) ===
        window.showPodstroikaModal = function(podId) {
            const modal = document.getElementById('naryad-podstroika-modal');
            if (!modal) return;

            const titleEl = document.getElementById('pod-modal-title');
            const subEl = document.getElementById('pod-modal-subtitle');
            const detailsEl = document.getElementById('pod-modal-details');
            const statusSel = document.getElementById('pod-modal-status');
            const saveBtn = document.getElementById('pod-modal-save-btn');

            // Получаем данные из кэша
            let pods = {};
            const cache = document.getElementById('naryad-podstroikas-cache');
            if (cache && cache.dataset.pods) {
                try { pods = JSON.parse(cache.dataset.pods); } catch(e){}
            }
            const pod = pods[podId];
            if (!pod) {
                alert('Данные подстройки не найдены');
                return;
            }

            titleEl.textContent = 'Подстройка смены';
            subEl.textContent = pod.for_month;

            detailsEl.textContent = pod.details || '(нет описания)';
            statusSel.value = pod.status || 'pending';

            // Сохранить статус
            saveBtn.onclick = function() {
                const newStatus = statusSel.value;
                saveBtn.disabled = true;
                saveBtn.textContent = 'Сохраняем...';

                fetch('/podstroiki/' + podId + '/status', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({ status: newStatus })
                })
                .then(r => r.json().catch(() => ({})))
                .then(data => {
                    if (data.success === false) {
                        alert(data.message || 'Ошибка');
                        return;
                    }
                    modal.classList.add('hidden');
                    // Перезагружаем сетку чтобы обновить отображение
                    const m = document.getElementById('naryad-month')?.value || '';
                    window.Naryad.loadPartial('{{ route('naryad.partial.setka') }}?month=' + m);
                })
                .catch((e) => {
                    console.error(e);
                    alert('Ошибка при сохранении статуса');
                })
                .finally(() => {
                    saveBtn.disabled = false;
                    saveBtn.textContent = 'Сохранить статус';
                });
            };

            modal.classList.remove('hidden');
        };

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
