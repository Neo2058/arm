<div class="max-w-[1700px]">
    <div class="flex items-end justify-between mb-4">
        <div>
            <h2 class="text-2xl font-bold">Сетка — {{ $month }}</h2>
            <p class="text-3xl text-orange-600 dark:text-orange-300 dark:text-orange-500 dark:text-orange-400">Слева список пользователей (ФИО). Пометки: <span class="font-medium">Б</span> — бригадир, <span class="font-medium">Т6</span> — управление составом т6, <span class="font-medium">М</span> — манёвры, <span class="font-medium">П</span> — помощник. Проставляйте маршрут напротив фамилии на дату. Информация о времени/месте — из WorkShift + RoutesCatalog.</p>
        </div>
        <div class="text-3xl px-3 py-1 bg-white dark:bg-[#0b1018] border border-gray-200 dark:border-white/10 rounded-2xl">
            {{ $daysInMonth }} дней • {{ $users->count() }} чел.
        </div>
    </div>

    <!-- Поиск по табельным номерам и ФИО для удобства фильтрации пользователей и подстроек -->
    <div class="mb-3 flex items-center gap-3">
        <input 
            type="text" 
            id="naryad-user-search" 
            placeholder="Поиск по таб. № или ФИО (фильтрует строки сетки)..." 
            class="px-4 py-2 border border-gray-300 dark:border-white/20 rounded-2xl text-xl bg-white dark:bg-[#0b1018] w-96 focus:outline-none focus:ring-2 focus:ring-orange-500"
            onkeyup="if(window.filterNaryadGrid) window.filterNaryadGrid(this.value)">
        <span class="text-sm text-orange-500">Подстройки будут показаны напротив фамилии</span>
    </div>

    <div class="overflow-x-auto border border-gray-200 dark:border-white/10 rounded-3xl bg-white dark:bg-[#0b1018]">
        <table class="min-w-full text-3xl">
            <thead class="bg-gray-50 dark:bg-white/5 sticky top-0 z-20">
                <tr>
                    <th class="sticky left-0 z-30 bg-white dark:bg-[#0b1018] px-4 py-3 text-left font-semibold border-r w-72">ФИО + пометки</th>
                    @for($d = 1; $d <= $daysInMonth; $d++)
                        @php
                            $dayDate = $start->copy()->day($d);
                            $dayStr = $dayDate->format('Y-m-d');
                            $ass = $dailyAssigned[$dayStr] ?? 0;
                            $quotaModel = $quotas->get($dayStr);
                            $req = $quotaModel ? $quotaModel->required_crews : 0;
                            $countColor = $ass >= $req && $req > 0 ? 'text-emerald-600 dark:text-emerald-400' : ($ass > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-orange-500 dark:text-orange-400');

                            // Русские сокращения дней недели
                            $weekdaysRu = [1 => 'пн', 2 => 'вт', 3 => 'ср', 4 => 'чт', 5 => 'пт', 6 => 'сб', 7 => 'вс'];
                            $dow = $dayDate->dayOfWeekIso;
                            $dowRu = $weekdaysRu[$dow] ?? '?';

                            // Показываем /req только если квота задана (чтобы не было 1/0 на днях без настройки в календаре)
                            $countText = $req > 0 ? $ass . '/' . $req : (string) $ass;
                        @endphp
                        <th class="px-2 py-2 text-center font-mono text-[33px] border-r min-w-[42px]">
                            {{ $d }}<br>
                            <span class="text-[27px] text-orange-500 dark:text-orange-400">{{ $dowRu }}</span>
                            <span class="block text-[30px] font-semibold {{ $countColor }}">{{ $countText }}</span>
                        </th>
                    @endfor
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                @forelse($users as $u)
                    @php
                        $p = $u->profile;
                        $nameParts = preg_split('/\s+/', trim($u->name));
                        $fio = ($nameParts[0] ?? '') . ' ' . (isset($nameParts[1]) ? mb_substr($nameParts[1],0,1).'.' : '') . (isset($nameParts[2]) ? mb_substr($nameParts[2],0,1).'.' : '');
                        $badges = [];
                        if ($p && $p->is_brigadir) $badges[] = ['Б', 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300'];
                        if ($p && $p->can_manage_t6) $badges[] = ['Т6', 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300'];
                        if ($p && $p->can_maneuvers) $badges[] = ['М', 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300'];
                        if ($p && $p->is_pomoshnik) $badges[] = ['П', 'bg-violet-100 text-violet-700 dark:bg-violet-900/40 dark:text-violet-300'];
                    @endphp
                    <tr class="hover:bg-gray-50 dark:hover:bg-white/5 naryad-user-row" data-tab="{{ $p && $p->tab_number ? $p->tab_number : '' }}" data-name="{{ $fio }}">
                        <td class="sticky left-0 z-20 bg-white dark:bg-[#0b1018] px-4 py-2.5 border-r text-3xl font-medium">
                            {{ $fio }}
                            @foreach($badges as $b)
                                <span class="ml-1 px-1.5 py-px rounded text-[27px] font-semibold {{ $b[1] }}">{{ $b[0] }}</span>
                            @endforeach
                            @if($p && $p->tab_number)
                                <span class="ml-2 text-[30px] font-mono text-orange-500 dark:text-orange-400">{{ $p->tab_number }}</span>
                            @endif

                            @if($norm)
                                @php
                                    $mh = $monthHours[$u->id] ?? 0;
                                    $qh = $quarterHours[$u->id] ?? 0;
                                    $yh = $yearHours[$u->id] ?? 0;
                                    $monthly = $norm->monthly_hours ?? [];
                                    $mLim = $monthly[$month] ?? $norm->month_hours;
                                    $yLim = $norm->year_hours;
                                    $devs = $userDevCounts[$u->id] ?? [];
                                    $extra = '';
                                    foreach ($devs as $code => $cnt) {
                                        if ($cnt > 0) $extra .= ' ' . $code . ':' . $cnt;
                                    }
                                    $lastH = $userLastShiftHours[$u->id] ?? 0;
                                    $w = ' W:' . $lastH;
                                @endphp
                                <div class="text-[24px] leading-tight mt-px font-mono text-orange-600 dark:text-orange-400" title="Накопительные часы за месяц/квартал/год + часы последней смены + счётчики отвлечений">
                                    М:<span class="font-semibold text-orange-700 dark:text-orange-300">{{ $mh }}</span>/{{ $mLim }}
                                    К:{{ $qh }} Г:<span class="font-semibold text-orange-700 dark:text-orange-300">{{ $yh }}</span>/{{ $yLim }}{{ $w }}{{ $extra }}
                                </div>
                            @endif

                            <!-- Подстройки для этой фамилии (поддержка нескольких, лимит задаёт нарядчик) -->
                            @php
                                $userPods = $podstroikas[$u->id] ?? collect();
                                $userLimit = $podstroikaLimits[$u->id] ?? null;
                                $maxApproved = $userLimit ? $userLimit->max_approved : 1;
                                $approvedCount = $userPods->where('status', 'podstroeno')->count();
                            @endphp
                            <div class="mt-1 pt-1 border-t border-orange-200 dark:border-white/10 text-[15px] bg-orange-50/20 dark:bg-white/5 rounded px-1 py-0.5">
                                <div class="flex items-center gap-1 mb-0.5">
                                    <span class="font-medium text-orange-600">Подстр. {{ $approvedCount }} / </span>
                                    <input type="number" min="0" value="{{ $maxApproved }}" 
                                           class="pod-limit-input w-10 text-center border rounded px-1 py-0 text-sm bg-white dark:bg-[#0b1018]" 
                                           data-user-id="{{ $u->id }}" data-month="{{ $start->format('Y-m') }}">
                                    <button type="button" class="save-pod-limit-btn text-[10px] px-1 py-0.5 border rounded hover:bg-orange-100" data-user-id="{{ $u->id }}">✓</button>
                                </div>
                                @if($userPods->isNotEmpty())
                                    @foreach($userPods as $pod)
                                        <div class="podstroiki-clickable flex gap-1 items-baseline cursor-pointer hover:bg-orange-100 dark:hover:bg-orange-900/30 rounded px-0.5 -mx-0.5 transition-colors text-[14px]"
                                             data-pod-id="{{ $pod->id }}"
                                             title="Клик для просмотра и согласования">
                                            <span class="font-mono text-orange-500">{{ \Illuminate\Support\Str::limit($pod->details, 25) }}</span>
                                            <span class="ml-auto text-[11px] px-1 rounded {{ $pod->status === 'podstroeno' ? 'bg-green-200 text-green-800' : 'bg-yellow-200 text-yellow-800' }}">
                                                {{ $pod->status === 'podstroeno' ? '✓' : '⏳' }}
                                            </span>
                                        </div>
                                    @endforeach
                                @else
                                    <div class="text-[13px] text-orange-300 dark:text-orange-700/50">— нет заявок</div>
                                @endif
                            </div>
                        </td>

                        @for($d = 1; $d <= $daysInMonth; $d++)
                            @php
                                $dayKey = $start->copy()->day($d)->format('Y-m-d');
                                $assigned = $assignments[$u->id][$dayKey] ?? null;
                            @endphp
                            <td class="px-1 py-1 text-center border-r text-3xl align-middle cursor-pointer hover:bg-orange-50 dark:hover:bg-orange-950/30"
                                data-user-id="{{ $u->id }}"
                                data-date="{{ $dayKey }}"
                                title="{{ $assigned ? 'Клик по маршруту — изменить; × — удалить назначение' : 'Клик, чтобы назначить маршрут' }}">
                                @if($assigned)
                                    @php
                                        $info = $routeDetails[$assigned] ?? [];
                                    @endphp
                                    <span class="inline-flex items-center gap-0.5 group" title="{{ $assigned }}{{ !empty($info) ? ' | ' . ($info['start_time'] ?? '') . ' ' . ($info['start_loc'] ?? '') . ' → ' . ($info['end_time'] ?? '') . ' ' . ($info['end_loc'] ?? '') . ' (' . ($info['duration'] ?? '') . ')' : '' }}">
                                        <span class="inline-block px-1 py-0.5 rounded bg-orange-100 dark:bg-orange-900/40 text-orange-700 dark:text-orange-300 font-semibold assign-value text-[30px] leading-none text-center">
                                            {{ $assigned }}
                                            @if(!empty($info['start_time']))
                                                <span class="block text-[21px] text-orange-600 dark:text-orange-400">{{ $info['start_time'] }} → {{ $info['end_time'] }}</span>
                                            @endif
                                        </span>
                                        <span class="delete-assign-btn text-[10px] leading-none px-0.5 text-red-400 hover:text-red-600 dark:text-red-300 dark:hover:text-red-400 cursor-pointer select-none font-bold opacity-30 group-hover:opacity-100 transition-opacity" title="Удалить назначенную смену">×</span>
                                    </span>
                                @else
                                    <span class="text-orange-300 dark:text-orange-600">—</span>
                                @endif
                            </td>
                        @endfor
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ $daysInMonth + 1 }}" class="px-6 py-10 text-center text-orange-500 dark:text-orange-400">
                            Нет пользователей с профилями. Создайте профили через админку / Filament (UserProfile).
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-3 flex items-center gap-4 text-[11px] text-orange-500 dark:text-orange-400">
        <div>Б = бригадир • Т6 = управление составом типа т6 • М = манёвры • П = помощник</div>
        <div class="flex-1 h-px bg-gray-100 dark:bg-white/10"></div>
        <div>Клик по ячейке → открыть модальное окно с подробностями маршрута (время, место, длительность) • × — удалить</div>
    </div>
</div>

<div id="naryad-routes-cache" data-routes='{{ $routesJson }}' style="display:none"></div>
<div id="naryad-variants-cache" data-variants='{{ $variantsJson }}' style="display:none"></div>
<div id="naryad-deviations-cache" data-deviations='{{ $deviationsJson }}' style="display:none"></div>
<div id="naryad-daily-graphs-cache" data-graphs='{{ $dailyGraphsJson }}' style="display:none"></div>
<div id="naryad-daily-used-routes-cache" data-used-routes='{{ $dailyUsedRoutesJson }}' style="display:none"></div>
<div id="naryad-podstroikas-cache" data-pods='{{ $podstroikasJson }}' style="display:none"></div>
<div id="naryad-night-parities-cache" data-parities='{{ $dailyNightParitiesJson }}' style="display:none"></div>

<!-- Кэш накопительных часов (месяц/квартал/год/недели) для отображения рядом с ФИО и для показа остатка при назначении -->
<div id="naryad-user-hours-cache" data-hours='{{ $userHoursJson }}' style="display:none"></div>
<div id="naryad-norm-cache" data-week-limit="{{ $weekLimit }}" style="display:none"></div>

<script>
    // Фильтр строк сетки по табельному номеру или ФИО (для поиска подстроек)
    window.filterNaryadGrid = function(val) {
        val = (val || '').toLowerCase().trim();
        document.querySelectorAll('.naryad-user-row').forEach(function(tr) {
            const tab = (tr.dataset.tab || '').toLowerCase();
            const name = (tr.dataset.name || '').toLowerCase();
            const match = !val || tab.includes(val) || name.includes(val);
            tr.style.display = match ? '' : 'none';
        });
    };
    // Инициализация если нужно
    document.addEventListener('DOMContentLoaded', function() {
        const search = document.getElementById('naryad-user-search');
        if (search && search.value) window.filterNaryadGrid(search.value);
    });
</script>