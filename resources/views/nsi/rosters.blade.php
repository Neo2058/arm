@extends('nsi.layouts.nsi')
@section('content')
    <h1 class="text-2xl font-bold mb-1">Расстановка составов</h1>
    <p class="text-sm text-orange-600 mb-4">RASST (основная) и RASST1 (дополнительная). Маршруты берутся из кодификатора 43.</p>

    <form class="flex flex-wrap gap-3 items-end mb-4 text-sm">
        <label>Дата
            <input type="date" name="date" value="{{ $date }}" class="block border rounded-2xl px-3 py-2">
        </label>
        <label>Таблица
            <select name="kind" class="block border rounded-2xl px-3 py-2">
                <option value="primary" @selected($kind === 'primary')>основная</option>
                <option value="extra" @selected($kind === 'extra')>дополнительная</option>
            </select>
        </label>
        <button class="px-4 py-2 border rounded-2xl">Показать</button>
    </form>

    <form method="POST" action="{{ route('nsi.rosters.seed') }}" class="mb-6">
        @csrf
        <input type="hidden" name="date" value="{{ $date }}">
        <input type="hidden" name="kind" value="{{ $kind }}">
        <button class="px-4 py-2 bg-orange-600 text-white rounded-2xl text-sm">Заполнить из маршрутов (kodspr 43)</button>
    </form>

    <div class="space-y-2">
        @forelse($rows as $row)
            <div class="bg-white dark:bg-[#0b1018] border rounded-3xl px-4 py-3 flex flex-wrap gap-2 items-end text-sm">
                <form method="POST" action="{{ route('nsi.rosters.update', $row) }}" class="flex flex-wrap gap-2 items-end flex-1">
                    @csrf
                    @method('PUT')
                    <span class="w-16 font-mono">{{ $row->route_number }}</span>
                    <label class="text-xs">инструмент
                        <input name="instrument" value="{{ $row->instrument }}" maxlength="4" class="block w-24 border rounded-2xl px-3 py-1">
                    </label>
                    <button class="px-3 py-1 border rounded-2xl text-xs">Сохранить</button>
                </form>
                <form method="POST" action="{{ route('nsi.rosters.destroy', $row) }}">
                    @csrf
                    @method('DELETE')
                    <button class="px-3 py-1 border rounded-2xl text-xs text-red-600">Удалить</button>
                </form>
            </div>
        @empty
            <p class="text-orange-400 text-sm">На эту дату строк нет.</p>
        @endforelse
    </div>
@endsection
