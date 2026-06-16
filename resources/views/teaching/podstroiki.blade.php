@extends('teaching.layouts.index')

@section('content')
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    <h1 class="text-3xl sm:text-4xl font-bold text-orange-600 dark:text-orange-400 mb-6">Подстройки смен</h1>

    @if(session('success'))
        <div class="mb-4 p-3 bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-300 rounded-xl">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="mb-4 p-3 bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-300 rounded-xl">{{ session('error') }}</div>
    @endif

    @if($isDispatcher)
        <!-- Только для нарядчика: таблица заявок на текущий и следующий месяц -->
        <!-- Фильтр по месяцам -->
        <div class="mb-4 flex items-center gap-4">
            <form method="GET" action="{{ route('podstroiki.index') }}" class="flex items-center gap-2">
                <label class="text-sm text-orange-700 dark:text-orange-300">Месяц:</label>
                <select name="month" onchange="this.form.submit()" class="border border-gray-300 dark:border-white/20 rounded-xl px-3 py-1 bg-white dark:bg-[#0b1018] text-sm">
                    <option value="both" {{ ($monthFilter ?? 'both') === 'both' ? 'selected' : '' }}>Текущий и следующий</option>
                    <option value="current" {{ ($monthFilter ?? '') === 'current' ? 'selected' : '' }}>Только текущий</option>
                    <option value="next" {{ ($monthFilter ?? '') === 'next' ? 'selected' : '' }}>Только следующий</option>
                </select>
                <noscript><button type="submit" class="px-3 py-1 text-sm bg-gray-200 rounded">Применить</button></noscript>
            </form>
        </div>

        <div class="bg-white dark:bg-[#0b1018] border border-gray-200 dark:border-white/10 rounded-3xl shadow-sm overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-100 dark:border-white/10">
                <h2 class="text-xl font-semibold text-orange-600 dark:text-orange-400">Заявки на подстройку смен (текущий и следующий месяц)</h2>
                <p class="text-sm text-orange-600 dark:text-orange-400 mt-1">Отсортировано по табельному номеру (по возрастанию). Вы можете менять статус.</p>
            </div>

            @if($applicants->isEmpty())
                <div class="p-6 text-center text-orange-500 dark:text-orange-400">Заявок пока нет.</div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-white/10">
                        <thead class="bg-gray-50 dark:bg-white/5">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-orange-600 dark:text-orange-400 uppercase tracking-wider">Таб. №</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-orange-600 dark:text-orange-400 uppercase tracking-wider">Фамилия И.О.</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-orange-600 dark:text-orange-400 uppercase tracking-wider">Месяц</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-orange-600 dark:text-orange-400 uppercase tracking-wider">Описание заявки</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-orange-600 dark:text-orange-400 uppercase tracking-wider">Статус</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-orange-600 dark:text-orange-400 uppercase tracking-wider">Действие</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                            @foreach($applicants as $app)
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-mono text-orange-800 dark:text-orange-200">{{ $app['tab_number'] }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-orange-800 dark:text-orange-200">{{ $app['name'] }}</td>
                                    <td class="px-6 py-4 text-sm text-orange-600 dark:text-orange-400">{{ $app['month'] }}</td>
                                    <td class="px-6 py-4 text-sm text-orange-700 dark:text-orange-300 max-w-xs truncate" title="{{ $app['details'] }}">{{ mb_substr($app['details'], 0, 60) }}{{ mb_strlen($app['details']) > 60 ? '...' : '' }}</td>
                                    <td class="px-6 py-4">
                                        <span class="px-2.5 py-0.5 rounded-full text-xs font-medium 
                                            {{ $app['status'] === 'podstroeno' ? 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300' : 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-300' }}">
                                            {{ $app['status'] === 'podstroeno' ? 'Подстроено' : 'В ожидании' }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-4">
                                        <form method="POST" action="{{ route('podstroiki.update-status', $app['id']) }}" class="flex items-center gap-2">
                                            @csrf
                                            <select name="status" class="text-sm border border-gray-300 dark:border-white/20 rounded px-2 py-1 bg-white dark:bg-[#0b1018]">
                                                <option value="pending" {{ $app['status'] === 'pending' ? 'selected' : '' }}>В ожидании</option>
                                                <option value="podstroeno" {{ $app['status'] === 'podstroeno' ? 'selected' : '' }}>Подстроено</option>
                                            </select>
                                            <button type="submit" class="px-3 py-1 text-xs bg-blue-600 hover:bg-blue-700 text-white rounded">Сохранить</button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

    @else
        <!-- Обычные пользователи: форма + только свои подстройки -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Форма подачи заявки на следующий месяц -->
            <div class="bg-white dark:bg-[#0b1018] border border-gray-200 dark:border-white/10 rounded-3xl p-6 shadow-sm">
                <h2 class="text-xl font-semibold mb-4 text-orange-600 dark:text-orange-400">Подать заявку на подстройку смены</h2>
                <p class="text-sm text-orange-700 dark:text-orange-300 mb-4">Заявка на следующий месяц. После отправки она появится в вашей таблице.</p>

                <form method="POST" action="{{ route('podstroiki.store') }}">
                    @csrf
                    <div class="mb-4">
                        <label class="block text-sm font-medium mb-1 text-orange-600 dark:text-orange-400">Месяц</label>
                        <input type="text" value="{{ \Carbon\Carbon::parse($nextMonth)->format('F Y') }}" disabled
                               class="w-full px-3 py-2 border border-gray-300 dark:border-white/20 rounded-xl bg-gray-100 dark:bg-white/5 text-sm">
                        <input type="hidden" name="for_month" value="{{ $nextMonth }}">
                    </div>

                    <div class="mb-4">
                        <label class="block text-sm font-medium mb-1 text-orange-600 dark:text-orange-400">Описание подстройки (что именно меняете)</label>
                        <textarea name="details" required rows="4" placeholder="Например: прошу поменять смену 15-го на 18-е..."
                                  class="w-full px-3 py-2 border border-gray-300 dark:border-white/20 rounded-2xl bg-white dark:bg-black/20 text-sm focus:outline-none"></textarea>
                    </div>

                    <button type="submit" class="w-full px-6 py-3 bg-orange-500 hover:bg-orange-600 text-white font-semibold rounded-2xl transition active:scale-[0.985]">
                        Отправить заявку
                    </button>
                </form>
            </div>

            <!-- Таблица своих подстроек -->
            <div class="bg-white dark:bg-[#0b1018] border border-gray-200 dark:border-white/10 rounded-3xl shadow-sm overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-100 dark:border-white/10">
                    <h2 class="text-xl font-semibold text-orange-600 dark:text-orange-400">Ваши заявки на подстройку</h2>
                </div>

                @if($myPodstroikas->isEmpty())
                    <div class="p-6 text-center text-orange-500 dark:text-orange-400">У вас пока нет заявок.</div>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-white/10">
                            <thead class="bg-gray-50 dark:bg-white/5">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-orange-600 dark:text-orange-400 uppercase tracking-wider">Месяц</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-orange-600 dark:text-orange-400 uppercase tracking-wider">Описание</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-orange-600 dark:text-orange-400 uppercase tracking-wider">Статус</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                                @foreach($myPodstroikas as $p)
                                    <tr>
                                        <td class="px-6 py-4 text-sm font-medium text-orange-800 dark:text-orange-200">{{ $p->for_month->format('Y-m') }}</td>
                                        <td class="px-6 py-4 text-sm text-orange-700 dark:text-orange-300">{{ mb_substr($p->details, 0, 80) }}{{ strlen($p->details) > 80 ? '...' : '' }}</td>
                                        <td class="px-6 py-4">
                                            <span class="px-2.5 py-0.5 rounded-full text-xs font-medium 
                                                {{ $p->status === 'pending' ? 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-300' : 'bg-green-100 text-green-800' }}">
                                                {{ ucfirst($p->status) }}
                                            </span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    @endif
</div>
@endsection
