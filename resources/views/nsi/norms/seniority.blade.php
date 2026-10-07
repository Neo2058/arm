@extends('nsi.layouts.nsi')
@section('content')
    <h1 class="text-2xl font-bold mb-1">Выслуга лет</h1>
    <p class="text-sm text-orange-600 mb-6">VISL: интервал полных лет и процент.</p>

    <form method="POST" action="{{ route('nsi.seniority.store') }}" class="bg-white dark:bg-[#0b1018] border rounded-3xl p-4 mb-6 grid md:grid-cols-4 gap-3 text-sm">
        @csrf
        <label>От (лет)
            <input name="years_from" type="number" min="0" required class="block w-full border rounded-2xl px-3 py-2">
        </label>
        <label>До (лет)
            <input name="years_to" type="number" min="0" required class="block w-full border rounded-2xl px-3 py-2">
        </label>
        <label>Процент
            <input name="percent" type="number" step="0.01" required class="block w-full border rounded-2xl px-3 py-2">
        </label>
        <div class="flex items-end">
            <button class="px-5 py-2 bg-orange-600 text-white rounded-2xl text-sm font-semibold">Сохранить</button>
        </div>
    </form>

    <div class="space-y-2">
        @foreach($rows as $row)
            <div class="bg-white dark:bg-[#0b1018] border rounded-3xl px-4 py-3 flex flex-wrap gap-2 items-end text-sm">
                <form method="POST" action="{{ route('nsi.seniority.update', $row) }}" class="flex flex-wrap gap-2 items-end flex-1">
                    @csrf
                    @method('PUT')
                    <input name="years_from" type="number" value="{{ $row->years_from }}" class="w-24 border rounded-2xl px-3 py-1">
                    <input name="years_to" type="number" value="{{ $row->years_to }}" class="w-24 border rounded-2xl px-3 py-1">
                    <input name="percent" type="number" step="0.01" value="{{ $row->percent }}" class="w-28 border rounded-2xl px-3 py-1">
                    <button class="px-3 py-1 border rounded-2xl text-xs">Сохранить</button>
                </form>
                <form method="POST" action="{{ route('nsi.seniority.destroy', $row) }}">
                    @csrf
                    @method('DELETE')
                    <button class="px-3 py-1 border rounded-2xl text-xs text-red-600">Удалить</button>
                </form>
            </div>
        @endforeach
    </div>
@endsection
