@extends('nsi.layouts.nsi')
@section('content')
    <h1 class="text-2xl font-bold mb-1">Справочники</h1>
    <p class="text-sm text-orange-600 mb-6">Связь с экранами нарядчика и кодификаторами. Вторые таблицы не заводятся.</p>
    <div class="grid md:grid-cols-2 gap-3">
        @foreach($items as $item)
            <a href="{{ $item['href'] }}" class="block bg-white dark:bg-[#0b1018] border rounded-3xl px-4 py-3 hover:border-orange-400">
                <div class="font-medium">{{ $item['title'] }}</div>
                @if(!empty($item['note']))
                    <div class="text-xs text-orange-500">{{ $item['note'] }}</div>
                @endif
            </a>
        @endforeach
    </div>
@endsection
