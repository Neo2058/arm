<x-filament-panels::page>
    <section class="space-y-4 rounded-xl border p-4">
        <h2 class="text-xl font-bold">Создать карточку</h2>
        <form wire:submit="createCard" class="space-y-4">
            <label class="block">Название <input wire:model="data.title" maxlength="255" required class="block w-full rounded-lg text-gray-900"></label>
            <label class="block">Задание <textarea wire:model="data.instructions" maxlength="20000" required rows="4" class="block w-full rounded-lg text-gray-900"></textarea></label>
            <label class="block">Срок включительно <input type="date" wire:model="data.due_date" required class="rounded-lg text-gray-900"></label>
            <label class="block"><input type="checkbox" wire:model.live="data.all_instructors"> Всем действующим инструкторам</label>
            @unless($data['all_instructors'])
                <fieldset class="max-h-48 overflow-y-auto"><legend>Исполнители</legend>
                    @foreach($instructors as $instructor)
                        <label class="block" wire:key="instructor-{{ $instructor->id }}"><input type="checkbox" wire:model="data.assignee_ids" value="{{ $instructor->id }}"> {{ $instructor->name }} · колонна {{ $instructor->profile?->column ?? 'не указана' }}</label>
                    @endforeach
                </fieldset>
            @endunless
            <p class="text-sm">Список «всем» фиксируется при создании. У каждого инструктора будет отдельное исполнение и отчёт.</p>
            <fieldset class="space-y-2"><legend>Обязательные материалы</legend>
                <label class="block"><input type="checkbox" wire:model="data.require_response"> Текстовый ответ</label>
                <label class="block"><input type="checkbox" wire:model="data.require_report_file"> Файл отчёта</label>
                <label class="block"><input type="checkbox" wire:model="data.require_explanation_file"> Объяснение отдельным файлом</label>
            </fieldset>
            @if($errors->any())<div role="alert" class="text-red-600">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
            <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="createCard">Выдать поручение</x-filament::button>
        </form>
    </section>
    <section wire:poll.15s class="space-y-4" aria-label="Мониторинг отчётов">
        <p class="text-sm">Автоматическое обновление каждые 15 секунд, пока страница открыта. Проверено: {{ now()->timezone(config('discipline.timezone'))->format('d.m.Y H:i:s') }}.</p>
        <div wire:offline role="alert" class="text-red-600">Соединение потеряно. Данные могут быть устаревшими.</div>
        <label>Карточка <select wire:model.live="cardId" class="rounded-lg text-gray-900"><option value="">Все карточки</option>@foreach($cards as $card)<option value="{{ $card->id }}">№{{ $card->id }} {{ $card->title }}</option>@endforeach</select></label>
        <div class="flex flex-wrap gap-4">
            @foreach(\App\Models\DisciplineAssignment::LABELS as $key => $label)<span>{{ $label }}: <strong>{{ $counts[$key] ?? 0 }}</strong></span>@endforeach
            <span>Просрочено: <strong>{{ $overdue }}</strong></span>
        </div>
        <p>Непрочитанных событий: <strong>{{ $unreadCount }}</strong>. Часовой пояс: {{ config('discipline.timezone') }}.</p>
        <h2 class="text-xl font-bold">Последние поступившие отчёты</h2>
        <div class="overflow-x-auto"><table class="w-full text-sm"><thead><tr><th class="text-left">Инструктор</th><th class="text-left">Карточка</th><th>Поступил</th><th>Попытка</th><th>Проверка</th></tr></thead><tbody>
            @forelse($reports as $report)
                <tr wire:key="report-{{ $report->id }}" class="border-b"><td class="p-2">{{ $report->assignment->assignee->name }}</td><td><a class="underline" href="{{ route('discipline.show', $report->assignment_id) }}">{{ $report->assignment->card->title }}</a></td><td>{{ $report->submitted_at->timezone(config('discipline.timezone'))->format('d.m.Y H:i:s') }}</td><td class="text-center">{{ $report->attempt }}</td><td>{{ ['accepted' => 'Принят', 'returned' => 'Возвращён'][$report->decision] ?? 'Ожидает проверки' }}</td></tr>
            @empty<tr><td colspan="5" class="p-4">Отчётов пока нет.</td></tr>@endforelse
        </tbody></table></div>
        <h2 class="text-xl font-bold">Исполнение по инструкторам</h2>
        <label>Состояние <select wire:model.live="state" class="rounded-lg text-gray-900"><option value="">Все</option>@foreach(\App\Models\DisciplineAssignment::LABELS as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></label>
        <div class="overflow-x-auto"><table class="w-full text-sm"><thead><tr><th class="text-left">Инструктор</th><th class="text-left">Карточка</th><th>Срок</th><th>Состояние</th></tr></thead><tbody>
            @forelse($assignments as $assignment)
                <tr wire:key="assignment-{{ $assignment->id }}" class="border-b"><td class="p-2">{{ $assignment->assignee->name }} · {{ $assignment->column_snapshot }}</td><td><a class="underline" href="{{ route('discipline.show', $assignment->id) }}">{{ $assignment->card->title }}</a></td><td>{{ $assignment->card->due_date->format('d.m.Y') }}</td><td>{{ \App\Models\DisciplineAssignment::LABELS[$assignment->status] }} @if($assignment->is_overdue)<strong class="text-red-600">Просрочено</strong>@endif</td></tr>
            @empty<tr><td colspan="4" class="p-4">Поручений нет.</td></tr>@endforelse
        </tbody></table></div>
        {{ $assignments->links() }}
    </section>
</x-filament-panels::page>
