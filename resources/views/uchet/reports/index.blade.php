@extends('uchet.layouts.uchet')
@section('content')
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold">Отчёты и справки</h1>
            <p class="text-sm text-orange-600">Меню DOKMENU: наряды и справки учёта. Печать — HTML из тех же таблиц.</p>
        </div>
        <form class="flex items-center gap-2">
            <input type="month" name="month" value="{{ $month }}" class="border rounded-2xl px-3 py-2" onchange="this.form.submit()">
        </form>
    </div>

    <form id="rep-params" class="bg-white dark:bg-[#0b1018] border rounded-3xl p-4 mb-6 grid md:grid-cols-4 gap-3 text-sm">
        <label>Месяц
            <input type="month" name="month" value="{{ $month }}" class="block w-full border rounded-2xl px-3 py-2">
        </label>
        <label>С
            <input type="date" name="from" value="{{ $from }}" class="block w-full border rounded-2xl px-3 py-2">
        </label>
        <label>По
            <input type="date" name="to" value="{{ $to }}" class="block w-full border rounded-2xl px-3 py-2">
        </label>
        <label>Дата
            <input type="date" name="date" value="{{ $date }}" class="block w-full border rounded-2xl px-3 py-2">
        </label>
        <label>С месяца
            <input type="month" name="from_month" value="{{ $from_month }}" class="block w-full border rounded-2xl px-3 py-2">
        </label>
        <label>По месяц
            <input type="month" name="to_month" value="{{ $to_month }}" class="block w-full border rounded-2xl px-3 py-2">
        </label>
        <label>Работник
            <select name="user_id" class="block w-full border rounded-2xl px-3 py-2">
                <option value="">—</option>
                @foreach($people as $p)
                    <option value="{{ $p->user_id }}">{{ $p->tab_number }} {{ $p->full_name }}</option>
                @endforeach
            </select>
        </label>
        <label>Пометка PRIM1
            <select name="mark" class="block w-full border rounded-2xl px-3 py-2">
                <option value="">—</option>
                @foreach($marks as $m)
                    <option value="{{ $m }}">{{ $m }}</option>
                @endforeach
            </select>
        </label>
        <label>Состав внутрисменных
            <select name="extra_mode" class="block w-full border rounded-2xl px-3 py-2">
                <option value="all">полный список</option>
                <option value="with">отработавшие</option>
                <option value="without">не отработавшие</option>
            </select>
        </label>
    </form>

    @php
        $groups = [
            'naryad' => 'Наряды и отчёты',
            'sprav' => 'Справки',
        ];
    @endphp
    @foreach($groups as $key => $label)
        <h2 class="font-semibold mb-2 mt-6">{{ $label }}</h2>
        <div class="grid md:grid-cols-2 gap-3 mb-4">
            @foreach($catalog as $item)
                @if($item['group'] === $key)
                    <button type="button" class="rep-open text-left bg-white dark:bg-[#0b1018] border rounded-3xl px-4 py-3 hover:border-orange-400" data-kind="{{ $item['kind'] }}">
                        <div class="font-medium">{{ $item['title'] }}</div>
                        <div class="text-xs text-orange-500">{{ implode(', ', $item['needs']) }}</div>
                    </button>
                @endif
            @endforeach
            @if($key === 'naryad' && auth()->user()?->isDispatcher())
                <a href="{{ route('naryad.print', ['date' => $date, 'kind' => 'full']) }}" class="block bg-white dark:bg-[#0b1018] border rounded-3xl px-4 py-3 hover:border-orange-400">
                    <div class="font-medium">Печать нарядов</div>
                    <div class="text-xs text-orange-500">DOKMENU pecnar · дата</div>
                </a>
                <a href="{{ route('naryad.print', ['date' => $date, 'kind' => 'extract']) }}" class="block bg-white dark:bg-[#0b1018] border rounded-3xl px-4 py-3 hover:border-orange-400">
                    <div class="font-medium">Выписка в комнату отдыха</div>
                    <div class="text-xs text-orange-500">DOKMENU pecnar tip=2 · дата</div>
                </a>
            @endif
        </div>
    @endforeach
    <script>
        document.querySelectorAll('.rep-open').forEach(btn => {
            btn.addEventListener('click', () => {
                const form = document.getElementById('rep-params');
                const q = new URLSearchParams(new FormData(form));
                window.location = @json(url('/uchet/reports')) + '/' + btn.dataset.kind + '?' + q.toString();
            });
        });
    </script>
@endsection
