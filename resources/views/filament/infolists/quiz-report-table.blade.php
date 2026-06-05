<div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-white/10 bg-gray-800 dark:bg-zinc-900 shadow-sm">
    <table class="w-full text-left border-collapse">
        <thead>
            <tr class="bg-gray-50 dark:bg-white/5 border-b border-gray-200 dark:border-white/10 text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-zinc-400">
                <th class="p-4 w-2/5">Вопрос</th>
                <th class="p-4 w-1/5">Ответ на вопрос</th>
                <th class="p-4 w-1/5">Правильный ответ</th>
                <th class="p-4 w-1/5 text-center">Статус</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-200 dark:divide-white/10 text-sm">
@foreach($getRecord()->detailed_report as $item)
    <tr class="hover:bg-gray-50/50 dark:hover:bg-white/[0.02] transition">
        <td class="p-4 font-medium text-gray-900 dark:text-white">
            {{ $item['question'] }}
        </td>
        <td class="p-4 {{ $item['is_right'] ? 'text-emerald-600 dark:text-emerald-400' : 'text-danger-600 dark:text-danger-400 font-semibold' }}">
            {{ $item['user_answer'] }}
        </td>
        <td class="p-4 text-emerald-600 dark:text-emerald-400">
            {{ $item['correct_answer'] }}
        </td>
        <td class="p-4 text-center">
            @if($item['is_right'])
                <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-emerald-100 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-400">
                                Верно
                            </span>
            @else
                <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-rose-100 text-rose-800 dark:bg-rose-500/10 dark:text-rose-400">
                                Ошибка
                            </span>
            @endif
        </td>
    </tr>
@endforeach
</tbody>
</table>
</div>
