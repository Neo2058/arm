@extends('nsi.layouts.nsi')
@section('content')
    <h1 class="text-2xl font-bold mb-1">Процент премии</h1>
    <p class="text-sm text-orange-600 mb-6">PREM: должность, признак бригадира, процент.</p>

    <form method="POST" action="{{ route('nsi.premiums.store') }}" class="bg-white dark:bg-[#0b1018] border rounded-3xl p-4 mb-6 grid md:grid-cols-4 gap-3 text-sm">
        @csrf
        <label>Должность
            <input name="position_code" required maxlength="8" class="block w-full border rounded-2xl px-3 py-2">
        </label>
        <label>Процент
            <input name="percent" type="number" step="0.01" required class="block w-full border rounded-2xl px-3 py-2">
        </label>
        <label>Название
            <input name="name" maxlength="40" class="block w-full border rounded-2xl px-3 py-2">
        </label>
        <label class="flex items-center gap-2 mt-6">
            <input type="checkbox" name="is_brigadier" value="1"> бригадир
        </label>
        <div class="md:col-span-4">
            <button class="px-5 py-2 bg-orange-600 text-white rounded-2xl text-sm font-semibold">Сохранить</button>
        </div>
    </form>

    <div class="space-y-2">
        @foreach($rows as $row)
            <div class="bg-white dark:bg-[#0b1018] border rounded-3xl px-4 py-3 flex flex-wrap gap-2 items-end text-sm">
                <form method="POST" action="{{ route('nsi.premiums.update', $row) }}" class="flex flex-wrap gap-2 items-end flex-1">
                    @csrf
                    @method('PUT')
                    <span class="w-20 py-1">{{ $row->position_code }}</span>
                    <span class="w-24 py-1 text-xs">{{ $row->is_brigadier ? 'бригадир' : '' }}</span>
                    <input name="percent" type="number" step="0.01" value="{{ $row->percent }}" class="w-28 border rounded-2xl px-3 py-1">
                    <input name="name" value="{{ $row->name }}" class="flex-1 min-w-[8rem] border rounded-2xl px-3 py-1">
                    <button class="px-3 py-1 border rounded-2xl text-xs">Сохранить</button>
                </form>
                <form method="POST" action="{{ route('nsi.premiums.destroy', $row) }}">
                    @csrf
                    @method('DELETE')
                    <button class="px-3 py-1 border rounded-2xl text-xs text-red-600">Удалить</button>
                </form>
            </div>
        @endforeach
    </div>
@endsection
