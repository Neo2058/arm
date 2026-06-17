<div class="max-w-[1700px]">
    <div class="flex items-end justify-between mb-4">
        <div>
            <h2 class="text-2xl font-bold">Сетка — {{ $month }}</h2>
            <p class="text-sm text-orange-600 dark:text-orange-300 dark:text-orange-500 dark:text-orange-400">Слева список пользователей (ФИО). Пометки: <span class="font-medium">Б</span> — бригадир, <span class="font-medium">Т6</span> — управление составом т6, <span class="font-medium">М</span> — манёвры, <span class="font-medium">П</span> — помощник. Проставляйте маршрут напротив фамилии на дату. Информация о времени/месте — из WorkShift + RoutesCatalog.</p>
        </div>
        <div class="text-xs px-3 py-1 bg-white dark:bg-[#0b1018] border border-gray-200 dark:border-white/10 rounded-2xl">
            {{ $daysInMonth }} дней • {{ $users->count() }} чел.
        </div>
    </div>

    <div class="overflow-x-auto border border-gray-200 dark:border-white/10 rounded-3xl bg-white dark:bg-[#0b1018]">
        <table class="min-w-full text-sm">
            <thead class="bg-gray-50 dark:bg-white/5 sticky top-0 z-20">
                <tr>
                    <th class="sticky left-0 z-30 bg-white dark:bg-[#0b1018] px-4 py-3 text-left font-semibold border-r w-72">ФИО + пометки</th>
                    @for($d = 1; $d <= $daysInMonth; $d++)
                        @php
                            $dayStr = $start->copy()->day($d)->format('Y-m-d');
                            $ass = $dailyAssigned[$dayStr] ?? 0;
                            $quotaModel = $quotas->get($dayStr);
                            $req = $quotaModel ? $quotaModel->required_crews : 0;
                            $countColor = $ass >= $req && $req > 0 ? 'text-emerald-600 dark:text-emerald-400' : ($ass > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-orange-500 dark:text-orange-400');
                        @endphp
                        <th class="px-2 py-2 text-center font-mono text-[11px] border-r min-w-[42px]">
                            {{ $d }}<br>
                            <span class="text-[9px] text-orange-500 dark:text-orange-400">{{ $start->copy()->day($d)->format('D') }}</span>
                            <span class="block text-[10px] font-semibold {{ $countColor }}">{{ $ass }}/{{ $req }}</span>
                            @if($norm)
                            <span class="block text-[8px] text-orange-500 dark:text-orange-400">W: /{{ $norm->week_hours }}</span>
                            @endif
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
                    <tr class="hover:bg-gray-50 dark:hover:bg-white/5">
                        <td class="sticky left-0 z-20 bg-white dark:bg-[#0b1018] px-4 py-2.5 border-r text-sm font-medium whitespace-nowrap">
                            {{ $fio }}
                            @foreach($badges as $b)
                                <span class="ml-1 px-1.5 py-px rounded text-[9px] font-semibold {{ $b[1] }}">{{ $b[0] }}</span>
                            @endforeach
                            @if($p && $p->tab_number)
                                <span class="ml-2 text-[10px] font-mono text-orange-500 dark:text-orange-400">{{ $p->tab_number }}</span>
                            @endif
                        </td>

                        @for($d = 1; $d <= $daysInMonth; $d++)
                            @php
                                $dayKey = $start->copy()->day($d)->format('Y-m-d');
                                $assigned = $assignments[$u->id][$dayKey] ?? null;
                            @endphp
                            <td class="px-1 py-1 text-center border-r text-xs align-middle cursor-pointer hover:bg-orange-50 dark:hover:bg-orange-950/30"
                                data-user-id="{{ $u->id }}"
                                data-date="{{ $dayKey }}"
                                title="{{ $assigned ? 'Маршрут ' . $assigned . ' — клик для изменения' : 'Клик, чтобы назначить маршрут' }}">
                                @if($assigned)
                                    <span class="inline-block px-1.5 py-0.5 rounded bg-orange-100 dark:bg-orange-900/40 text-orange-700 dark:text-orange-300 font-semibold">{{ $assigned }}</span>
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
        <div>Клик по ячейке → ввести/изменить номер маршрута → сохранится мгновенно (перезагрузка сетки)</div>
    </div>
</div>

<div id="naryad-routes-cache" data-routes='{{ $routesJson }}' style="display:none"></div>
<div id="naryad-variants-cache" data-variants='{{ $variantsJson }}' style="display:none"></div>
<div id="naryad-deviations-cache" data-deviations='{{ $deviationsJson }}' style="display:none"></div>
<div id="naryad-daily-graphs-cache" data-graphs='{{ $dailyGraphsJson }}' style="display:none"></div>