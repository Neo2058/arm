@extends('nsi.layouts.nsi')
@section('content')
    <h1 class="text-2xl font-bold mb-1">Настройка аванса</h1>
    <p class="text-sm text-orange-600 mb-6">NRDPAR.NR_AVANS → <code>naryad_norms.planir.advance</code>.</p>
    <form method="POST" action="{{ route('nsi.advance.update') }}" class="bg-white dark:bg-[#0b1018] border rounded-3xl p-6 max-w-md text-sm">
        @csrf
        @method('PUT')
        <label>Величина аванса
            <input name="advance" value="{{ $planir['advance'] ?? '' }}" maxlength="16" class="block w-full border rounded-2xl px-3 py-2 mt-1">
        </label>
        <button class="mt-4 px-5 py-2 bg-orange-600 text-white rounded-2xl text-sm font-semibold">Сохранить</button>
    </form>
@endsection
