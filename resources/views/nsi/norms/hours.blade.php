@extends('nsi.layouts.nsi')
@section('content')
    <h1 class="text-2xl font-bold mb-1">Нормы времени</h1>
    <p class="text-sm text-orange-600 mb-6">NRCHAS: месячная и дневная норма. PLANIR считает потолок как dnnr × рабочие дни + doppr.</p>

    <form method="POST" action="{{ route('nsi.hours.store') }}" class="bg-white dark:bg-[#0b1018] border rounded-3xl p-4 mb-6 grid md:grid-cols-4 gap-3 text-sm">
        @csrf
        <label>Месяц
            <input type="month" name="year_month" required class="block w-full border rounded-2xl px-3 py-2">
        </label>
        <label>Месячная норма
            <input name="month_hours" type="number" step="0.001" required class="block w-full border rounded-2xl px-3 py-2">
        </label>
        <label>Дневная норма
            <input name="day_hours" type="number" step="0.001" required class="block w-full border rounded-2xl px-3 py-2">
        </label>
        <div class="flex items-end">
            <button class="px-5 py-2 bg-orange-600 text-white rounded-2xl text-sm font-semibold">Сохранить</button>
        </div>
    </form>

    <div class="space-y-2">
        @foreach($rows as $row)
            <div class="bg-white dark:bg-[#0b1018] border rounded-3xl px-4 py-3 flex flex-wrap gap-2 items-end text-sm">
                <form method="POST" action="{{ route('nsi.hours.update', $row) }}" class="flex flex-wrap gap-2 items-end flex-1">
                    @csrf
                    @method('PUT')
                    <span class="w-24 py-1 font-mono">{{ $row->year_month }}</span>
                    <input name="month_hours" type="number" step="0.001" value="{{ $row->month_hours }}" class="w-28 border rounded-2xl px-3 py-1">
                    <input name="day_hours" type="number" step="0.001" value="{{ $row->day_hours }}" class="w-28 border rounded-2xl px-3 py-1">
                    <button class="px-3 py-1 border rounded-2xl text-xs">Сохранить</button>
                </form>
                <form method="POST" action="{{ route('nsi.hours.destroy', $row) }}" onsubmit="return confirm('Удалить норму?')">
                    @csrf
                    @method('DELETE')
                    <button class="px-3 py-1 border rounded-2xl text-xs text-red-600">Удалить</button>
                </form>
            </div>
        @endforeach
    </div>
@endsection
