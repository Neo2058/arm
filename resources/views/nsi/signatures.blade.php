@extends('nsi.layouts.nsi')
@section('content')
    <h1 class="text-2xl font-bold mb-1">Подписи ответственных</h1>
    <p class="text-sm text-orange-600 mb-6">PODPISI: должность и ФИО для печати наряда.</p>

    <form method="POST" action="{{ route('nsi.signatures.store') }}" class="bg-white dark:bg-[#0b1018] border rounded-3xl p-4 mb-6 grid md:grid-cols-4 gap-3 text-sm">
        @csrf
        <label>Порядок
            <input name="sort_order" type="number" value="0" class="block w-full border rounded-2xl px-3 py-2">
        </label>
        <label class="md:col-span-2">Должность
            <input name="position" required maxlength="45" class="block w-full border rounded-2xl px-3 py-2">
        </label>
        <label>Ф.И.О.
            <input name="full_name" maxlength="18" class="block w-full border rounded-2xl px-3 py-2">
        </label>
        <div class="md:col-span-4">
            <button class="px-5 py-2 bg-orange-600 text-white rounded-2xl text-sm font-semibold">Добавить</button>
        </div>
    </form>

    <div class="space-y-2">
        @foreach($signatures as $row)
            <div class="bg-white dark:bg-[#0b1018] border rounded-3xl px-4 py-3 flex flex-wrap gap-2 items-end text-sm">
                <form method="POST" action="{{ route('nsi.signatures.update', $row) }}" class="flex flex-wrap gap-2 items-end flex-1">
                    @csrf
                    @method('PUT')
                    <input name="sort_order" type="number" value="{{ $row->sort_order }}" class="w-20 border rounded-2xl px-3 py-1">
                    <input name="position" value="{{ $row->position }}" maxlength="45" class="flex-1 min-w-[12rem] border rounded-2xl px-3 py-1">
                    <input name="full_name" value="{{ $row->full_name }}" maxlength="18" class="w-40 border rounded-2xl px-3 py-1">
                    <button class="px-3 py-1 border rounded-2xl text-xs">Сохранить</button>
                </form>
                <form method="POST" action="{{ route('nsi.signatures.destroy', $row) }}" onsubmit="return confirm('Удалить подпись?')">
                    @csrf
                    @method('DELETE')
                    <button class="px-3 py-1 border rounded-2xl text-xs text-red-600">Удалить</button>
                </form>
            </div>
        @endforeach
    </div>
@endsection
