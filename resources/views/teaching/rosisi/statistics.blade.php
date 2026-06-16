@extends('teaching.layouts.index')

@section('content')
<div class="max-w-7xl mx-auto">
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-3xl font-bold text-gray-900 dark:text-white">Статистика росписей и записей в формуляр</h1>
        <a href="{{ route('rosisi.index') }}" class="text-sm px-4 py-2 bg-gray-200 dark:bg-gray-700 hover:bg-gray-300 rounded-xl transition">
            ← Вернуться к росписям
        </a>
    </div>

    <!-- Росписи документов -->
    <div class="mb-10">
        <h2 class="text-2xl font-semibold mb-4 text-orange-600">Росписи по документам инструктажей</h2>

        <div class="bg-white dark:bg-[#0b1018] border border-gray-200 dark:border-white/10 rounded-3xl shadow overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-white/10">
                    <thead class="bg-gray-50 dark:bg-white/5">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Пользователь</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Документ</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Папка</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Дата росписи</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                        @forelse($signatures as $sig)
                            <tr>
                                <td class="px-4 py-3 text-sm font-medium text-gray-900 dark:text-white">{{ $sig->user->name ?? '—' }}</td>
                                <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-300">{{ $sig->document->title ?? '—' }}</td>
                                <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400">{{ $sig->category->name ?? '—' }}</td>
                                <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400">{{ $sig->signed_at ? $sig->signed_at->format('d.m.Y H:i') : '—' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-4 py-8 text-center text-gray-500">Нет данных о росписях.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="px-4 py-3 border-t border-gray-200 dark:border-white/10">
                {{ $signatures->links() }}
            </div>
        </div>
    </div>

    <!-- Записи в формуляр -->
    <div>
        <h2 class="text-2xl font-semibold mb-4 text-orange-600">Записи в формуляр</h2>

        <div class="bg-white dark:bg-[#0b1018] border border-gray-200 dark:border-white/10 rounded-3xl shadow overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-white/10">
                    <thead class="bg-gray-50 dark:bg-white/5">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Пользователь</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Задача</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Дата выполнения</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Текст записи</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                        @forelse($logs as $log)
                            <tr>
                                <td class="px-4 py-3 text-sm font-medium text-gray-900 dark:text-white">{{ $log->user->name ?? '—' }}</td>
                                <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-300">{{ $log->task->title ?? '—' }}</td>
                                <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400">{{ $log->completed_at ? $log->completed_at->format('d.m.Y H:i') : '—' }}</td>
                                <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-300 max-w-xs truncate" title="{{ $log->entry_text }}">
                                    {{ $log->entry_text ?: '—' }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-4 py-8 text-center text-gray-500">Нет записей в формуляр.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="px-4 py-3 border-t border-gray-200 dark:border-white/10">
                {{ $logs->links() }}
            </div>
        </div>
    </div>
</div>
@endsection
