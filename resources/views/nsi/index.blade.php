@extends('nsi.layouts.nsi')
@section('content')
    <h1 class="text-2xl font-bold mb-1">Нормативно-справочная информация</h1>
    <p class="text-sm text-orange-600 mb-6">Меню NSIMENU: кодификаторы, справочники, нормативы, настройка АРМ. Печать DOS, перекрёстные ссылки и порядок полей не переносятся.</p>
    <div class="grid md:grid-cols-2 gap-4">
        @foreach($pads as $pad)
            <div class="bg-white dark:bg-[#0b1018] border rounded-3xl p-5">
                <h2 class="font-semibold mb-3">{{ $pad['title'] }}</h2>
                <ul class="space-y-2 text-sm">
                    @foreach($pad['items'] as $item)
                        <li>
                            <a href="{{ $item['href'] }}" class="hover:text-orange-500">{{ $item['title'] }}</a>
                            @if(!empty($item['note']))
                                <span class="text-[11px] text-orange-400"> · {{ $item['note'] }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    </div>
@endsection
