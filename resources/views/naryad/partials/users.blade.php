<div class="max-w-6xl">
    <h2 class="text-2xl font-bold mb-1">Расширенный справочник пользователей</h2>
    <p class="text-sm text-orange-700 dark:text-orange-200 mb-4">Здесь нарядчик может быстро проставлять пометки, необходимые для планирования: бригадир, возможность управления т6, манёвры М, помощник (П). Эти данные используются в сетке. Показываются только водители (роль driver).</p>

    <div class="bg-white dark:bg-[#0b1018] border border-gray-200 dark:border-white/10 rounded-3xl overflow-hidden">
        <table class="min-w-full text-sm">
            <thead class="bg-gray-50 dark:bg-white/5">
                <tr>
                    <th class="px-6 py-3 text-left">ФИО / Таб. №</th>
                    <th class="px-4 py-3 text-center">Бригадир</th>
                    <th class="px-4 py-3 text-center">Т6</th>
                    <th class="px-4 py-3 text-center">М (манёвры)</th>
                    <th class="px-4 py-3 text-center">П (помощник)</th>
                    <th class="px-4 py-3">Примечание</th>
                    <th></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                @forelse($users as $u)
                    @php $p = $u->profile; @endphp
                    <tr data-profile-id="{{ $p?->id }}">
                        <td class="px-6 py-3">{{ $u->name }} <span class="text-xs text-orange-600 dark:text-orange-300">{{ $p->tab_number ?? '' }}</span></td>
                        <td class="px-4 py-3 text-center">
                            <input type="checkbox" class="flag-brigadir" {{ $p && $p->is_brigadir ? 'checked' : '' }}>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <input type="checkbox" class="flag-t6" {{ $p && $p->can_manage_t6 ? 'checked' : '' }}>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <input type="checkbox" class="flag-maneuvers" {{ $p && $p->can_maneuvers ? 'checked' : '' }}>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <input type="checkbox" class="flag-pomoshnik" {{ $p && $p->is_pomoshnik ? 'checked' : '' }}>
                        </td>
                        <td class="px-4 py-3">
                            <input type="text" class="flag-notes text-xs border rounded px-2 py-0.5 w-48" value="{{ $p->additional_notes ?? '' }}">
                        </td>
                        <td class="px-4 py-3">
                            <button type="button"
                                    class="save-flags-btn text-xs px-3 py-1 border rounded-2xl hover:bg-orange-500/10"
                                    data-profile-id="{{ $p?->id }}">
                                Сохранить
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-6 py-8 text-center text-orange-600 dark:text-orange-300">Водители (роль driver) с профилями появятся здесь. Пока заглушка (реальные данные подтянутся автоматически, когда будут planning-поля в базе).</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="text-[11px] mt-2 text-orange-600 dark:text-orange-300">Изменения сохраняются по кнопке "Сохранить". Бейджи в Сетке обновятся при перезагрузке раздела (автоматически при сохранении).</div>
</div>