@extends('teaching.layouts.index')

@section('content')
<div class="max-w-5xl mx-auto px-4 pt-2 pb-6 lg:pt-6 lg:px-6">
    <h1 class="text-2xl font-bold text-orange-500 mb-1 pl-6 md:pl-0">Поиск по нарядам</h1>
    <p class="text-sm text-gray-600 dark:text-gray-400 mb-6 pl-6 md:pl-0">
        Загрузите свои файлы нарядов, разбивки смен и список ФИО. Поиск только по вашим файлам.
    </p>

    @if(session('success'))
        <div class="mb-4 p-3 bg-green-100 text-green-800 rounded-xl">{{ session('success') }}</div>
    @endif
    @if($errors->any())
        <div class="mb-4 p-3 bg-red-100 text-red-800 rounded-xl">{{ $errors->first() }}</div>
    @endif

    <div class="grid md:grid-cols-3 gap-4 mb-6">
        <div class="bg-white dark:bg-gray-800 rounded-2xl border p-4">
            <h2 class="font-semibold mb-2">Наряды (.txt, CP866/UTF-8)</h2>
            <form action="{{ route('journal.naryad-search.naryads') }}" method="POST" enctype="multipart/form-data" class="space-y-2">
                @csrf
                <input type="file" name="naryads[]" multiple accept=".txt,.TXT,text/plain" required
                       class="block w-full text-sm">
                <button class="w-full bg-orange-500 hover:bg-orange-600 text-white rounded-xl py-2 text-sm">Загрузить</button>
            </form>
            <ul class="mt-3 space-y-1 text-sm max-h-40 overflow-auto">
                @forelse($naryads as $f)
                    <li class="flex justify-between gap-2">
                        <span>{{ $f->original_name }}
                            @if($f->naryad_date)
                                <span class="text-gray-500">({{ $f->naryad_date->format('d.m.Y') }})</span>
                            @endif
                        </span>
                        <form action="{{ route('journal.naryad-search.naryads.destroy', $f) }}" method="POST" onsubmit="return confirm('Удалить?')">
                            @csrf @method('DELETE')
                            <button class="text-red-500 text-xs">×</button>
                        </form>
                    </li>
                @empty
                    <li class="text-gray-400">Пока нет</li>
                @endforelse
            </ul>
        </div>

        <div class="bg-white dark:bg-gray-800 rounded-2xl border p-4">
            <h2 class="font-semibold mb-2">Разбивка смен (PDF или .txt)</h2>
            <p class="text-xs text-gray-500 mb-2">PDF «Рабочие…» / «Выходные…» разбирается сам, как в прототипе.</p>
            <form action="{{ route('journal.naryad-search.shifts') }}" method="POST" enctype="multipart/form-data" class="space-y-2">
                @csrf
                <input type="file" name="shifts[]" multiple accept=".pdf,.txt,.TXT,application/pdf,text/plain" required
                       class="block w-full text-sm">
                <select name="kind" class="w-full border rounded-xl px-2 py-1 text-sm bg-white dark:bg-gray-900">
                    <option value="auto">Определить автоматически</option>
                    <option value="work">Рабочие дни</option>
                    <option value="weekend">Выходные</option>
                </select>
                <button class="w-full bg-orange-500 hover:bg-orange-600 text-white rounded-xl py-2 text-sm">Загрузить</button>
            </form>
            <ul class="mt-3 space-y-1 text-sm max-h-40 overflow-auto">
                @forelse($shifts as $f)
                    <li class="flex justify-between gap-2">
                        <span>{{ $f->original_name }}
                            <span class="text-gray-500">({{ $f->kind === 'weekend' ? 'вых.' : 'раб.' }})</span>
                        </span>
                        <form action="{{ route('journal.naryad-search.shifts.destroy', $f) }}" method="POST" onsubmit="return confirm('Удалить?')">
                            @csrf @method('DELETE')
                            <button class="text-red-500 text-xs">×</button>
                        </form>
                    </li>
                @empty
                    <li class="text-gray-400">Пока нет</li>
                @endforelse
            </ul>
        </div>

        <div class="bg-white dark:bg-gray-800 rounded-2xl border p-4">
            <h2 class="font-semibold mb-2">Списки ФИО (.txt)</h2>
            <form action="{{ route('journal.naryad-search.queries') }}" method="POST" enctype="multipart/form-data" class="space-y-2">
                @csrf
                <input type="file" name="queries[]" multiple accept=".txt,.TXT,text/plain" required
                       class="block w-full text-sm">
                <button class="w-full bg-orange-500 hover:bg-orange-600 text-white rounded-xl py-2 text-sm">Загрузить</button>
            </form>
            <ul class="mt-3 space-y-1 text-sm max-h-40 overflow-auto">
                @forelse($queries as $f)
                    <li class="flex justify-between gap-2">
                        <span>{{ $f->original_name }}
                            <span class="text-gray-500">({{ count($f->queries ?? []) }})</span>
                        </span>
                        <form action="{{ route('journal.naryad-search.queries.destroy', $f) }}" method="POST" onsubmit="return confirm('Удалить?')">
                            @csrf @method('DELETE')
                            <button class="text-red-500 text-xs">×</button>
                        </form>
                    </li>
                @empty
                    <li class="text-gray-400">Пока нет</li>
                @endforelse
            </ul>
        </div>
    </div>

    <form action="{{ route('journal.naryad-search.run') }}" method="POST" class="bg-white dark:bg-gray-800 rounded-2xl border p-4 mb-6">
        @csrf
        <p class="text-sm text-gray-500 mb-3">Если ничего не отмечать — берутся все ваши файлы.</p>
        <div class="grid md:grid-cols-3 gap-4 text-sm mb-4">
            <div>
                <div class="font-medium mb-1">Наряды</div>
                @foreach($naryads as $f)
                    <label class="flex gap-2 items-center">
                        <input type="checkbox" name="naryad_ids[]" value="{{ $f->id }}" checked>
                        {{ $f->original_name }}
                    </label>
                @endforeach
            </div>
            <div>
                <div class="font-medium mb-1">Разбивки</div>
                @foreach($shifts as $f)
                    <label class="flex gap-2 items-center">
                        <input type="checkbox" name="shift_ids[]" value="{{ $f->id }}" checked>
                        {{ $f->original_name }}
                    </label>
                @endforeach
            </div>
            <div>
                <div class="font-medium mb-1">ФИО</div>
                @foreach($queries as $f)
                    <label class="flex gap-2 items-center">
                        <input type="checkbox" name="query_ids[]" value="{{ $f->id }}" checked>
                        {{ $f->original_name }}
                    </label>
                @endforeach
            </div>
        </div>
        <button class="bg-orange-500 hover:bg-orange-600 text-white rounded-xl px-6 py-2 font-medium">Найти</button>
    </form>

    @if($result)
        <div class="flex items-center justify-between mb-2">
            <h2 class="font-semibold">Результат</h2>
            <a href="{{ route('journal.naryad-search.download') }}" class="text-sm text-orange-500">Скачать .txt</a>
        </div>
        <pre class="whitespace-pre-wrap text-xs sm:text-sm bg-gray-50 dark:bg-gray-900 border rounded-2xl p-4 overflow-auto max-h-[70vh] font-mono">{{ $result }}</pre>
    @endif
</div>
@endsection
