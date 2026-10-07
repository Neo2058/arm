<div class="max-w-5xl">
    <h2 class="text-2xl font-bold mb-1">Контроль сетки</h2>
    <p class="text-sm text-orange-700 dark:text-orange-200 mb-4">
        Как F6 / Shift+F5 в <code>PLANIR</code>: все ли люди расставлены и все ли смены графика назначены.
        Нарушение здесь не блокирует клетку — это отчёт. Линия {{ $line_mode }} лица.
    </p>

    @php $issueDays = collect($days)->where('has_issues'); @endphp

    @if($issueDays->isEmpty())
        <div class="bg-white dark:bg-[#0b1018] border border-gray-200 dark:border-white/10 rounded-3xl p-6 text-sm text-orange-600">
            По месяцу {{ $month }} замечаний нет.
        </div>
    @endif

    <div class="space-y-4">
        @foreach($days as $day)
            @continue(! $day['has_issues'])
            <div class="bg-white dark:bg-[#0b1018] border border-gray-200 dark:border-white/10 rounded-3xl p-5 text-sm">
                <div class="font-semibold mb-2">{{ $day['label'] }}
                    @if($day['graph'])
                        <span class="text-xs font-normal text-orange-500">график {{ $day['graph'] }}</span>
                    @endif
                </div>
                @if($day['missing_people'])
                    <div class="mb-2">
                        <div class="text-xs uppercase tracking-wide text-orange-500 mb-1">Нет назначения</div>
                        <ul class="list-disc pl-5 space-y-0.5">
                            @foreach($day['missing_people'] as $p)
                                <li>{{ $p['name'] }}@if($p['tab']) <span class="text-orange-400">{{ $p['tab'] }}</span>@endif @if($p['position']) ({{ $p['position'] }})@endif</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
                @if($day['missing_shifts'])
                    <div class="mb-2">
                        <div class="text-xs uppercase tracking-wide text-orange-500 mb-1">Не назначены смены</div>
                        <div>{{ implode(', ', $day['missing_shifts']) }}</div>
                    </div>
                @endif
                @if($day['short_crew'])
                    <div class="mb-2">
                        <div class="text-xs uppercase tracking-wide text-orange-500 mb-1">На смене только 1 человек</div>
                        <div>{{ implode(', ', $day['short_crew']) }}</div>
                    </div>
                @endif
                @if($day['duplicates'])
                    <div>
                        <div class="text-xs uppercase tracking-wide text-orange-500 mb-1">Дубликаты</div>
                        <div>{{ implode(', ', $day['duplicates']) }}</div>
                    </div>
                @endif
            </div>
        @endforeach
    </div>
</div>
