@extends('uchet.layouts.uchet')
@section('content')
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold">Лицевые счета {{ $month }}</h1>
        <form><input type="month" name="month" value="{{ $month }}" class="border rounded-2xl px-3 py-2" onchange="this.form.submit()"></form>
    </div>
    @forelse($accounts as $a)
        <div class="mb-6 bg-white dark:bg-[#0b1018] border rounded-3xl p-5">
            <div class="font-semibold mb-2">{{ $a->user?->name }} · таб. {{ $a->user?->personnel?->tab_number }} · {{ $a->position_code }} · {{ $a->formula_kind }}</div>
            <table class="min-w-full text-xs">
                <thead class="text-orange-600"><tr>
                    <th class="text-left py-1">Статья</th><th>Код</th><th class="text-right">Тариф</th><th class="text-right">%</th><th class="text-right">Часы</th><th>Шифр</th>
                </tr></thead>
                <tbody>
                @foreach($a->lines as $line)
                    <tr class="border-t">
                        <td class="py-1">{{ $line->name }}</td>
                        <td class="text-center">{{ $line->pay_code }}</td>
                        <td class="text-right">{{ $line->tariff }}</td>
                        <td class="text-right">{{ $line->percent }}</td>
                        <td class="text-right font-medium">{{ $line->hours }}</td>
                        <td class="text-center">{{ $line->cost_code }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @empty
        <p class="text-orange-500">ЛС ещё нет. Нажмите «Собрать ЛС» на странице карточек.</p>
    @endforelse
@endsection
