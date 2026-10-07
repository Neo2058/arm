@extends('nsi.layouts.nsi')
@section('content')
    <h1 class="text-2xl font-bold mb-1">Форма лицевого счета</h1>
    <p class="text-sm text-orange-600 mb-4">Отбор строк FLSM/FLSP (поле OTBOR). Формулы считает интерпретатор, здесь только галочка «уч».</p>
    <div class="flex gap-2 mb-6 text-sm">
        <a href="{{ route('nsi.formulas', ['kind' => 'machinist']) }}" class="px-4 py-2 rounded-2xl border {{ $kind === 'machinist' ? 'bg-orange-500/10 border-orange-400' : '' }}">для машинистов</a>
        <a href="{{ route('nsi.formulas', ['kind' => 'assistant']) }}" class="px-4 py-2 rounded-2xl border {{ $kind === 'assistant' ? 'bg-orange-500/10 border-orange-400' : '' }}">для помощников</a>
    </div>
    <div class="space-y-2">
        @forelse($rows as $row)
            <form method="POST" action="{{ route('nsi.formulas.update', $row) }}" class="bg-white dark:bg-[#0b1018] border rounded-3xl px-4 py-3 flex flex-wrap gap-3 items-center text-sm">
                @csrf
                @method('PUT')
                <span class="w-10 font-mono">{{ $row->nom }}</span>
                <span class="flex-1">{{ $row->name }}</span>
                <label class="inline-flex items-center gap-2 text-xs">
                    <input type="checkbox" name="selected" value="1" @checked($row->selected)> уч
                </label>
                <button class="px-3 py-1 border rounded-2xl text-xs">Сохранить</button>
            </form>
        @empty
            <p class="text-orange-400 text-sm">Формулы не импортированы. <code>php artisan arm:import-dbf --only=formulas</code></p>
        @endforelse
    </div>
@endsection
