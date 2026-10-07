@extends('nsi.layouts.nsi')
@section('content')
    <h1 class="text-2xl font-bold mb-1">Названия справочников</h1>
    <p class="text-sm text-orange-600 mb-6">KODIF: код, название, доступ, длины полей.</p>

    <form method="POST" action="{{ route('nsi.books.store') }}" class="bg-white dark:bg-[#0b1018] border rounded-3xl p-4 mb-6 grid md:grid-cols-5 gap-3 text-sm">
        @csrf
        <label>Код
            <input name="kodspr" required maxlength="2" class="block w-full border rounded-2xl px-3 py-2">
        </label>
        <label class="md:col-span-2">Название
            <input name="nazspr" required maxlength="30" class="block w-full border rounded-2xl px-3 py-2">
        </label>
        <label>Доступ
            <input name="nsisr" maxlength="1" class="block w-full border rounded-2xl px-3 py-2">
        </label>
        <div class="flex items-end">
            <button class="px-5 py-2 bg-orange-600 text-white rounded-2xl text-sm font-semibold">Добавить</button>
        </div>
    </form>

    <div class="space-y-2">
        @foreach($books as $book)
            <form method="POST" action="{{ route('nsi.books.update', $book) }}" class="bg-white dark:bg-[#0b1018] border rounded-3xl px-4 py-3 grid md:grid-cols-6 gap-2 items-end text-sm">
                @csrf
                @method('PUT')
                <span class="font-mono py-1">{{ $book->kodspr }}</span>
                <input name="nazspr" value="{{ $book->nazspr }}" maxlength="30" class="md:col-span-2 border rounded-2xl px-3 py-1">
                <input name="nsisr" value="{{ $book->nsisr }}" maxlength="1" class="border rounded-2xl px-3 py-1" placeholder="доступ">
                <input name="dlkodel" type="number" value="{{ $book->dlkodel }}" class="border rounded-2xl px-3 py-1">
                <div class="flex gap-2">
                    <input name="dlnazel" type="number" value="{{ $book->dlnazel }}" class="w-20 border rounded-2xl px-3 py-1">
                    <button class="px-3 py-1 border rounded-2xl text-xs">Сохранить</button>
                </div>
            </form>
        @endforeach
    </div>
@endsection
