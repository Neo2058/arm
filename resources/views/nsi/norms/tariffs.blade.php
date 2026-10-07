@extends('nsi.layouts.nsi')
@section('content')
    <h1 class="text-2xl font-bold mb-1">Тарифные ставки</h1>
    <p class="text-sm text-orange-600 mb-6">ELKODIF kodspr=41 и таблица arm_tariffs пишутся вместе.</p>

    <form method="POST" action="{{ route('nsi.tariffs.store') }}" class="bg-white dark:bg-[#0b1018] border rounded-3xl p-4 mb-6 grid md:grid-cols-3 gap-3 text-sm">
        @csrf
        <label>Тариф (код)
            <input name="code" required maxlength="16" class="block w-full border rounded-2xl px-3 py-2" placeholder="176.81">
        </label>
        <label>Вид оплаты
            <input name="name" required maxlength="80" class="block w-full border rounded-2xl px-3 py-2">
        </label>
        <div class="flex items-end">
            <button class="px-5 py-2 bg-orange-600 text-white rounded-2xl text-sm font-semibold">Сохранить</button>
        </div>
    </form>

    <div class="space-y-2">
        @foreach($rows as $row)
            <div class="bg-white dark:bg-[#0b1018] border rounded-3xl px-4 py-3 flex flex-wrap gap-2 items-end text-sm">
                <form method="POST" action="{{ route('nsi.tariffs.update', $row) }}" class="flex flex-wrap gap-2 items-end flex-1">
                    @csrf
                    @method('PUT')
                    <span class="w-28 py-1 font-mono">{{ $row->code }}</span>
                    <input name="name" value="{{ $row->name }}" class="flex-1 min-w-[12rem] border rounded-2xl px-3 py-1">
                    <span class="text-xs text-orange-500">ставка {{ $row->rate }}</span>
                    <button class="px-3 py-1 border rounded-2xl text-xs">Сохранить</button>
                </form>
                <form method="POST" action="{{ route('nsi.tariffs.destroy', $row) }}">
                    @csrf
                    @method('DELETE')
                    <button class="px-3 py-1 border rounded-2xl text-xs text-red-600">Удалить</button>
                </form>
            </div>
        @endforeach
    </div>
@endsection
