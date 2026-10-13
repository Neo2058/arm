@php
    /** @var array $sheet */
@endphp
<div class="naryad-sheet font-mono text-[13px] leading-5 text-black whitespace-pre">
    <div class="text-center font-semibold tracking-wide uppercase">{{ $sheet['title'] }}</div>
    @if (!empty($sheet['graph_name']))
        <div class="text-center mt-1">График — {{ $sheet['graph_name'] }}</div>
    @endif
    <div class="mt-2 border-t border-b border-black py-0.5 flex gap-4 whitespace-normal">
        <span class="w-[13ch] shrink-0">{{ $sheet['header_route'] }}</span>
        <span class="flex-1">Фамилия</span>
        <span class="w-[16rem] shrink-0">Время Пункт</span>
    </div>

    @if ($sheet['message'])
        <div class="mt-4 text-center whitespace-normal">{{ $sheet['message'] }}</div>
    @endif

    @foreach ($sheet['rows'] as $row)
        @if (!empty($row['banner']))
            <div class="text-center">{{ $row['banner'] }}</div>
            @continue
        @endif
        <div class="flex gap-4 {{ !empty($row['vacant']) ? 'text-neutral-600' : '' }}">
            <span class="w-[13ch] shrink-0">{{ $row['route_label'] }}</span>
            <span class="flex-1 min-w-0">
                <span class="inline-block min-w-[11rem]">{{ $row['machinist'] }}</span>
                @if (($row['assistant'] ?? '') !== '')
                    <span class="inline-block min-w-[11rem]">{{ $row['assistant'] }}</span>
                @endif
            </span>
            <span class="w-[16rem] shrink-0">{{ $row['time'] }}@if (($row['note'] ?? '') !== '') {{ $row['note'] }}@endif</span>
        </div>
        @foreach ($row['note_lines'] ?? [] as $extra)
            <div>{{ $extra }}</div>
        @endforeach
    @endforeach

    @if (!empty($sheet['extra_text']))
        <div class="mt-2"></div>
        @foreach ($sheet['extra_text'] as $line)
            <div>{{ $line }}</div>
        @endforeach
    @endif

    @foreach ($sheet['absences'] as $block)
        <div class="mt-3 whitespace-normal">
            <div>  {{ $block['title'] }}</div>
            <div>  ────────────────</div>
            @foreach (array_chunk($block['names'], 4) as $chunk)
                <div>  {{ implode('   ', $chunk) }}</div>
            @endforeach
        </div>
    @endforeach

    @if (!empty($sheet['warnings']))
        <div class="mt-4 text-[12px] whitespace-normal">
            @foreach ($sheet['warnings'] as $warning)
                <div>! {{ $warning }}</div>
            @endforeach
        </div>
    @endif

    <div class="mt-8">
        @foreach ($sheet['signatures'] ?? [] as $sign)
            <div>        {{ $sign['position'] }} {{ $sign['name'] }}</div>
        @endforeach
        <div class="mt-4 text-right">{{ $sheet['printed_on'] }}</div>
    </div>
</div>
