@extends('nsi.layouts.nsi')
@section('content')
    <div class="mb-4">
        <a href="{{ route('nsi.codes') }}" class="text-sm text-orange-500">← кодификаторы</a>
        <h1 class="text-2xl font-bold mt-1">{{ $book->nazspr }}</h1>
        <p class="text-sm text-orange-600">Код справочника {{ $book->kodspr }}. Код до {{ $book->dlkodel }} знаков, название до {{ $book->dlnazel }}.</p>
    </div>

    <form method="POST" action="{{ route('nsi.codes.store', $book) }}" class="bg-white dark:bg-[#0b1018] border rounded-3xl p-4 mb-6 grid md:grid-cols-4 gap-3 text-sm">
        @csrf
        <label>Код
            <input name="code" maxlength="{{ $book->dlkodel }}" required class="block w-full border rounded-2xl px-3 py-2">
        </label>
        <label class="md:col-span-2">Название
            <input name="name" maxlength="{{ $book->dlnazel }}" required class="block w-full border rounded-2xl px-3 py-2">
        </label>
        <label>NOM
            <input name="nom" maxlength="5" class="block w-full border rounded-2xl px-3 py-2">
        </label>
        <div class="md:col-span-4">
            <button class="px-5 py-2 bg-orange-600 text-white rounded-2xl text-sm font-semibold">Добавить</button>
        </div>
    </form>

    <div class="space-y-2">
        @forelse($codes as $row)
            <div class="bg-white dark:bg-[#0b1018] border rounded-3xl px-4 py-3 flex flex-wrap gap-2 items-end text-sm">
                <form method="POST" action="{{ route('nsi.codes.update', [$book, $row]) }}" class="flex flex-wrap gap-2 items-end flex-1">
                    @csrf
                    @method('PUT')
                    <input name="code" value="{{ $row->code }}" maxlength="{{ $book->dlkodel }}" class="w-28 border rounded-2xl px-3 py-1">
                    <input name="name" value="{{ $row->name }}" maxlength="{{ $book->dlnazel }}" class="flex-1 min-w-[12rem] border rounded-2xl px-3 py-1">
                    <input name="nom" value="{{ $row->nom }}" maxlength="5" class="w-20 border rounded-2xl px-3 py-1">
                    <button class="px-3 py-1 border rounded-2xl text-xs">Сохранить</button>
                </form>
                <form method="POST" action="{{ route('nsi.codes.destroy', [$book, $row]) }}" onsubmit="return confirm('Удалить элемент?')">
                    @csrf
                    @method('DELETE')
                    <button class="px-3 py-1 border rounded-2xl text-xs text-red-600">Удалить</button>
                </form>
            </div>
        @empty
            <p class="text-orange-400 text-sm">Пусто</p>
        @endforelse
    </div>
    <div class="mt-4">{{ $codes->links() }}</div>
@endsection
