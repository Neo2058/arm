<x-filament::page>
    <div class="space-y-6">
        {{-- Summary Cards --}}
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div class="bg-white dark:bg-gray-800 rounded-xl p-6 shadow">
                <div class="text-sm text-gray-500 dark:text-gray-400">Всего записей</div>
                <div class="mt-1 text-4xl font-bold text-gray-900 dark:text-white">{{ $this->getStats()['total'] }}</div>
            </div>
            <div class="bg-white dark:bg-gray-800 rounded-xl p-6 shadow">
                <div class="text-sm text-gray-500 dark:text-gray-400">За последние 24 часа</div>
                <div class="mt-1 text-4xl font-bold text-orange-600">{{ $this->getStats()['last_24h'] }}</div>
            </div>
            <div class="bg-white dark:bg-gray-800 rounded-xl p-6 shadow">
                <div class="text-sm text-gray-500 dark:text-gray-400">За последние 7 дней</div>
                <div class="mt-1 text-4xl font-bold text-orange-600">{{ $this->getStats()['last_7d'] }}</div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            {{-- Actions breakdown --}}
            <div class="bg-white dark:bg-gray-800 rounded-2xl p-6 shadow">
                <h3 class="text-lg font-semibold mb-4 text-gray-900 dark:text-white">Действия по типам</h3>
                <div class="space-y-3">
                    @foreach ($this->getStats()['by_action'] as $action => $count)
                        <div class="flex justify-between items-center border-b border-gray-100 dark:border-gray-700 pb-2 last:border-0">
                            <div class="font-mono text-sm text-gray-600 dark:text-gray-300">{{ $action }}</div>
                            <div class="px-3 py-0.5 bg-orange-100 dark:bg-orange-900 text-orange-700 dark:text-orange-200 text-sm font-semibold rounded">
                                {{ $count }}
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Top active users --}}
            <div class="bg-white dark:bg-gray-800 rounded-2xl p-6 shadow">
                <h3 class="text-lg font-semibold mb-4 text-gray-900 dark:text-white">Топ активных пользователей</h3>
                @if (count($this->getStats()['top_users']))
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-500 dark:text-gray-400">
                                <th class="pb-2">Пользователь</th>
                                <th class="pb-2 text-right">Действий</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($this->getStats()['top_users'] as $user)
                                <tr class="border-t border-gray-100 dark:border-gray-700">
                                    <td class="py-2 font-medium">{{ $user['name'] }}</td>
                                    <td class="py-2 text-right font-semibold text-orange-600">{{ $user['count'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @else
                    <div class="text-gray-400">Нет данных</div>
                @endif
            </div>
        </div>

        <div class="text-xs text-gray-400">
            Статистика собирается автоматически при просмотре вкладок «Наряды», «Телефоны», «Расшифровки», открытии PDF и поиске. Доступно только супер-админу.
        </div>
    </div>
</x-filament::page>