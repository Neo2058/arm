@extends('uchet.layouts.uchet')
@section('content')
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold">{{ ($kind ?? 'primary') === 'repeat' ? 'Повторные лицевые счета' : 'Первичные лицевые счета' }} {{ $month }}</h1>
        <form class="flex items-center gap-2">
            <input type="hidden" name="kind" value="{{ $kind ?? 'primary' }}">
            <input type="month" name="month" value="{{ $month }}" class="border rounded-2xl px-3 py-2" onchange="this.form.submit()">
        </form>
    </div>
    <div class="mb-4 flex gap-3 text-sm">
        <a href="{{ route('uchet.accounts', ['month' => $month, 'kind' => 'primary']) }}" class="px-3 py-1 rounded-2xl {{ ($kind ?? 'primary') === 'primary' ? 'bg-orange-600 text-white' : 'border' }}">Первичные</a>
        <a href="{{ route('uchet.accounts', ['month' => $month, 'kind' => 'repeat']) }}" class="px-3 py-1 rounded-2xl {{ ($kind ?? 'primary') === 'repeat' ? 'bg-orange-600 text-white' : 'border' }}">Повторные (LSHPOVT)</a>
        @unless($period->isClosed())
            <button id="gen-kind-btn" class="px-3 py-1 border rounded-2xl">Собрать эти ЛС</button>
        @endunless
        <a href="{{ route('uchet.lsbuh.dbf', ['month' => $month, 'kind' => $kind ?? 'primary']) }}" class="px-3 py-1 border rounded-2xl">LSBUH.DBF</a>
        <a href="{{ route('uchet.lsbuh', ['month' => $month, 'kind' => $kind ?? 'primary']) }}" class="px-3 py-1 border rounded-2xl">CSV</a>
    </div>
    @forelse($accounts as $a)
        <div class="mb-6 bg-white dark:bg-[#0b1018] border rounded-3xl p-5">
            @php $t = $a->totals ?? []; @endphp
            <div class="font-semibold mb-1">{{ $a->user?->name }} · таб. {{ $a->user?->personnel?->tab_number }} · {{ $a->position_code }} · ЛС № {{ $a->ls_number }} · {{ $a->formula_kind }}</div>
            <div class="text-xs text-orange-500 mb-2">
                @if(!empty($t['period_from']))
                    период {{ \Carbon\Carbon::parse($t['period_from'])->format('d.m') }}–{{ \Carbon\Carbon::parse($t['period_to'])->format('d.m') }} ·
                @endif
                линия {{ $t['vchas1'] ?? 0 }}
                · dob1 {{ $t['dob1'] ?? 0 }}
                · nvih {{ $t['nvih'] ?? 0 }}
                · teh {{ $t['teh'] ?? 0 }}
                · медк {{ $t['medk'] ?? 0 }}
                · прем {{ $t['prem'] ?? 0 }}%
                · выслуга {{ $t['visl'] ?? 0 }} ({{ $t['vislp'] ?? 0 }}%)
                · норма {{ $t['tek_nr'] ?? 0 }}
            </div>
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
        <p class="text-orange-500">ЛС ещё нет. Нажмите «Собрать эти ЛС» или соберите на странице карточек.</p>
    @endforelse
    <script>
    document.getElementById('gen-kind-btn')?.addEventListener('click', async () => {
        const res = await fetch('{{ route('uchet.generate') }}', {
            method: 'POST',
            headers: {'Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}'},
            body: JSON.stringify({month: '{{ $month }}', kind: '{{ $kind ?? 'primary' }}'})
        });
        const data = await res.json();
        if (data.success) location.reload(); else alert(data.message || 'Ошибка');
    });
    </script>
@endsection
