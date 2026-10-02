@php
    /** @var array $sheet */
@endphp
<div class="naryad-sheet font-mono text-[13px] leading-5 text-black">
    <div class="text-center font-semibold tracking-wide uppercase">{{ $sheet['title'] }}</div>
    @if (!empty($sheet['graph_name']))
        <div class="text-center mt-1">График — {{ $sheet['graph_name'] }}</div>
    @endif
    <div class="mt-2 border-t border-b border-black py-0.5 flex gap-4">
        <span class="w-[7rem] shrink-0">{{ $sheet['header_route'] }}</span>
        <span class="flex-1">Фамилия</span>
        <span class="w-[16rem] shrink-0">Время Пункт</span>
    </div>

    @if ($sheet['message'])
        <div class="mt-4 text-center">{{ $sheet['message'] }}</div>
    @endif

    @foreach ($sheet['rows'] as $row)
        <div class="flex gap-4 {{ $row['vacant'] ? 'text-neutral-600' : '' }}">
            <span class="w-[7rem] shrink-0 whitespace-nowrap">{{ $row['route_label'] }}</span>
            <span class="flex-1 min-w-0">
                <span class="inline-block min-w-[11rem]">{{ $row['machinist'] }}</span>
                @if ($row['assistant'] !== '')
                    <span class="inline-block min-w-[11rem]">{{ $row['assistant'] }}</span>
                @endif
            </span>
            <span class="w-[16rem] shrink-0 whitespace-nowrap">{{ $row['time'] }} @if ($row['note'] !== '')<span class="text-[12px]">{{ $row['note'] }}</span>@endif</span>
        </div>
        @foreach ($row['note_lines'] as $extra)
            <div class="whitespace-pre">{{ $extra }}</div>
        @endforeach
    @endforeach

    @foreach ($sheet['absences'] as $block)
        <div class="mt-3">
            <div>{{ $block['title'] }}</div>
            <div>────────────────</div>
            @foreach (array_chunk($block['names'], 4) as $chunk)
                <div>{{ implode('   ', $chunk) }}</div>
            @endforeach
        </div>
    @endforeach

    @if (!empty($sheet['warnings']))
        <div class="mt-4 text-[12px]">
            @foreach ($sheet['warnings'] as $warning)
                <div>! {{ $warning }}</div>
            @endforeach
        </div>
    @endif

    <div class="mt-8">
        @if (!empty($user))
            <div>        Нарядчик      {{ $user->name }}</div>
        @endif
        <div class="mt-4 text-right">{{ $sheet['printed_on'] }}</div>
    </div>
</div>
