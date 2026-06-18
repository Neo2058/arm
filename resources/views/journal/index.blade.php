@extends('teaching.layouts.index')

@section('content')
<div class="max-w-4xl mx-auto px-4 py-6">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between mb-6">
        <div>
            <h1 class="text-3xl font-bold text-orange-500">Рабочий журнал ТЧМ</h1>
            <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">
                Колонна: <span class="font-semibold">{{ $column ?? 'Не указана' }}</span>
                @if(isset($error))
                    <span class="text-red-500 ml-2">({{ $error }})</span>
                @endif
            </p>
        </div>
        <div class="mt-4 sm:mt-0 text-xs text-gray-500">
            Мобильная версия • Для инструкторов
        </div>
    </div>

    @if(session('success'))
        <div class="mb-4 p-3 bg-green-100 text-green-700 rounded-xl">{{ session('success') }}</div>
    @endif

    <!-- Statistics - наглядно -->
    <div class="mb-8">
        <h2 class="text-lg font-semibold mb-3 text-gray-800 dark:text-gray-200">Статистика работы с колонной</h2>
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3">
            <div class="bg-white dark:bg-gray-800 p-4 rounded-2xl border shadow-sm">
                <div class="text-xs text-gray-500">Членов колонны</div>
                <div class="text-3xl font-bold text-orange-500">{{ $stats['column_members'] }}</div>
            </div>
            <div class="bg-white dark:bg-gray-800 p-4 rounded-2xl border shadow-sm">
                <div class="text-xs text-gray-500">Задач в работе</div>
                <div class="text-3xl font-bold">{{ $stats['tasks_pending'] }}</div>
            </div>
            <div class="bg-white dark:bg-gray-800 p-4 rounded-2xl border shadow-sm">
                <div class="text-xs text-gray-500">Выполнено</div>
                <div class="text-3xl font-bold text-green-600">{{ $stats['tasks_done'] }}</div>
            </div>
            <div class="bg-white dark:bg-gray-800 p-4 rounded-2xl border shadow-sm">
                <div class="text-xs text-gray-500">Документов</div>
                <div class="text-3xl font-bold">{{ $stats['documents_count'] }}</div>
            </div>
            <div class="bg-white dark:bg-gray-800 p-4 rounded-2xl border shadow-sm">
                <div class="text-xs text-gray-500">Нормативы %</div>
                <div class="text-3xl font-bold text-blue-500">{{ $stats['norms_completed'] }}%</div>
            </div>
            <div class="bg-white dark:bg-gray-800 p-4 rounded-2xl border shadow-sm">
                <div class="text-xs text-gray-500">Рапортов</div>
                <div class="text-3xl font-bold">{{ $stats['reports_written'] }}</div>
            </div>
        </div>
    </div>

    <!-- TODO - всегда на главной -->
    <div class="mb-8 bg-white dark:bg-gray-800 rounded-3xl p-5 border shadow-sm">
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-lg font-semibold">TODO Журнал (нормативы, рапорты, документы)</h2>
            <span class="text-xs bg-orange-100 text-orange-600 px-2 py-0.5 rounded-full">Всегда здесь</span>
        </div>

        <form action="{{ route('journal.todo.add') }}" method="POST" class="mb-4 flex flex-col sm:flex-row gap-2">
            @csrf
            <input type="text" name="title" placeholder="Название задачи / норматива / рапорта" class="flex-1 border rounded-xl px-3 py-2 text-sm" required>
            <input type="date" name="due_date" class="border rounded-xl px-3 py-2 text-sm">
            <select name="source" class="border rounded-xl px-3 py-2 text-sm">
                <option value="manual">Ручная</option>
                <option value="document_qa">Из документа</option>
                <option value="norm">Норматив</option>
                <option value="report">Рапорт</option>
            </select>
            <button type="submit" class="bg-orange-500 text-white px-4 py-2 rounded-xl text-sm font-medium">+ Добавить</button>
        </form>

        @if(collect($tasks)->count())
            <div class="space-y-2 max-h-[320px] overflow-auto pr-1">
                @foreach($tasks as $task)
                    <div class="flex items-start gap-3 border rounded-2xl p-3 {{ $task->status === 'done' ? 'opacity-60 line-through bg-gray-50' : '' }}">
                        <form action="{{ route('journal.todo.complete', $task->id) }}" method="POST" class="mt-1">
                            @csrf
                            <button type="submit" class="w-5 h-5 border rounded flex items-center justify-center {{ $task->status === 'done' ? 'bg-green-500 text-white' : '' }}">
                                @if($task->status === 'done') ✓ @endif
                            </button>
                        </form>
                        <div class="flex-1 text-sm">
                            <div class="font-medium">{{ $task->title }}</div>
                            @if($task->description)
                                <div class="text-xs text-gray-500 mt-0.5">{{ $task->description }}</div>
                            @endif
                            <div class="flex gap-2 text-[10px] mt-1 text-gray-400">
                                <span>{{ $task->source }}</span>
                                @if($task->due_date)
                                    <span>до {{ $task->due_date->format('d.m.Y') }}</span>
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            <p class="text-sm text-gray-400">Задачи появятся здесь. Добавляйте вручную или из ответов на документы.</p>
        @endif
    </div>

    <!-- Documents + Q&A -->
    <div class="bg-white dark:bg-gray-800 rounded-3xl p-5 border shadow-sm">
        <h2 class="text-lg font-semibold mb-4">Работа с документами (PDF + вопросы)</h2>

        <!-- Upload -->
        <form action="{{ route('journal.document.upload') }}" method="POST" enctype="multipart/form-data" class="mb-6">
            @csrf
            <div class="flex flex-col sm:flex-row gap-2 items-end">
                <div class="flex-1">
                    <label class="text-xs text-gray-500">Название документа</label>
                    <input type="text" name="title" class="w-full border rounded-xl px-3 py-2 text-sm" placeholder="Например: Задачи на июль" required>
                </div>
                <div>
                    <label class="text-xs text-gray-500">PDF файл</label>
                    <input type="file" name="pdf" accept=".pdf" class="text-sm" required>
                </div>
                <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded-xl text-sm">Загрузить</button>
            </div>
        </form>

        @if(collect($documents)->count())
            <div class="mb-4">
                <div class="text-sm font-medium mb-2">Загруженные документы</div>
                @foreach($documents as $doc)
                    <div class="border rounded-2xl p-3 mb-3 text-sm">
                        <div class="font-medium">{{ $doc->title }}</div>
                        <div class="text-xs text-gray-400 mb-2">{{ $doc->created_at->format('d.m.Y H:i') }}</div>

                        <!-- Ask form for this doc -->
                        <form action="{{ route('journal.ask') }}" method="POST" class="flex gap-2">
                            @csrf
                            <input type="hidden" name="document_id" value="{{ $doc->id }}">
                            <input type="text" name="question" class="flex-1 border rounded-xl px-3 py-1 text-sm" placeholder="Например: Какие задачи поставлены ТЧМ Светикову?" required>
                            <button type="submit" class="bg-orange-500 text-white px-3 py-1 rounded-xl text-sm">Спросить</button>
                        </form>

                        @if(session('qa_result') && session('qa_result')['doc_id'] == $doc->id)
                            <div class="mt-3 p-3 bg-orange-50 dark:bg-orange-900/20 rounded-xl text-xs">
                                <div class="font-semibold">Вопрос: {{ session('qa_result')['question'] }}</div>
                                <div class="mt-1">Ответ: {{ session('qa_result')['answer'] }}</div>
                                <form action="{{ route('journal.todo.add') }}" method="POST" class="mt-2">
                                    @csrf
                                    <input type="hidden" name="title" value="{{ session('qa_result')['answer'] }}">
                                    <input type="hidden" name="description" value="Из документа: {{ $doc->title }}">
                                    <input type="hidden" name="source" value="document_qa">
                                    <button type="submit" class="text-orange-600 hover:underline text-xs">+ Добавить этот ответ в TODO</button>
                                </form>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @else
            <p class="text-sm text-gray-400">Загрузите PDF, чтобы задавать вопросы по документам колонны.</p>
        @endif
    </div>

    <div class="mt-6 text-xs text-center text-gray-400">
        Мобильная версия рабочего журнала ТЧМ • Используйте меню слева для настроек, нормативов и истории
    </div>
</div>
@endsection