@extends('teaching.layouts.index')

@section('content')
<div class="max-w-7xl mx-auto px-4 pt-2 pb-6 lg:pt-6 lg:px-6">
    <h1 class="text-2xl font-bold mb-6 text-gray-900 dark:text-gray-200 pl-6 md:pl-0">Нормативы - Колонна {{ $column }}</h1>

    @if(session('success'))
        <div class="bg-green-100 p-3 mb-4 rounded">{{ session('success') }}</div>
    @endif

    <form action="{{ route('journal.standards.update') }}" method="POST">
        @csrf

        <!-- Tabs for each normative -->
        <div class="mb-4">
            <div class="flex border-b mb-4 overflow-x-auto">
                @foreach($types as $type)
                    <button type="button" onclick="showTab('{{ $type }}')" class="tab-button px-4 py-2 border-b-2 {{ $loop->first ? 'border-orange-500 text-orange-500' : 'border-transparent' }}" data-tab="{{ $type }}">
                        {{ $normativeLabels[$type] ?? str_replace('_', ' ', ucfirst($type)) }}
                    </button>
                @endforeach
            </div>

            @foreach($types as $type)
                <div id="tab-{{ $type }}" class="tab-content {{ $loop->first ? '' : 'hidden' }}">
                    <div class="overflow-x-auto -mx-1">
                    <table class="min-w-full text-sm bg-white dark:bg-gray-800 rounded-xl overflow-hidden text-gray-900 dark:text-gray-200 min-w-[720px]">
                        <thead>
                            <tr class="bg-gray-100 dark:bg-gray-700">
                                <th class="p-2 text-left text-gray-900 dark:text-gray-200">ФИО</th>
                                <th class="p-2 text-gray-900 dark:text-gray-200">Класс</th>
                                <th class="p-2 text-gray-900 dark:text-gray-200">Дата проведения</th>
                                <th class="p-2 text-gray-900 dark:text-gray-200">Дата следующего</th>
                                <th class="p-2 text-gray-900 dark:text-gray-200">Замечания</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($crew as $member)
                                @php 
                                    $norm = $normatives->get($member->id, collect())->firstWhere('type', $type);
                                    $currentClass = $member->profile?->normative_class ?? 'bk';
                                @endphp
                                <tr class="border-t" data-member-id="{{ $member->id }}">
                                    <td class="p-2 text-gray-900 dark:text-gray-200">{{ $member->name }}</td>
                                    <td class="p-2 text-gray-900 dark:text-gray-200">
                                        <select name="crew[{{ $member->id }}][class]" class="class-select border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white p-1 text-xs rounded focus:outline-none focus:ring-1 focus:ring-orange-500" data-member-id="{{ $member->id }}" data-type="{{ $type }}">
                                            @foreach(['bk' => 'б/к', '3' => '3-й', '2' => '2-й', '1' => '1-й'] as $val => $label)
                                                <option value="{{ $val }}" {{ $currentClass == $val ? 'selected' : '' }}>{{ $label }}</option>
                                            @endforeach
                                        </select>
                                    </td>
                                    <td class="p-2 text-gray-900 dark:text-gray-200">
                                        <input type="date" class="last-date border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white p-1 text-sm w-full rounded focus:outline-none focus:ring-1 focus:ring-orange-500" name="normatives[{{ $member->id }}][{{ $type }}][last_date]" 
                                               value="{{ $norm?->last_date?->format('Y-m-d') }}" data-member-id="{{ $member->id }}" data-type="{{ $type }}">
                                    </td>
                                    <td class="p-2 text-gray-900 dark:text-gray-200">
                                        <input type="date" class="next-date border border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-700 text-gray-900 dark:text-gray-200 p-1 text-sm w-full rounded focus:outline-none" name="normatives[{{ $member->id }}][{{ $type }}][next_date]" 
                                               value="{{ $norm?->next_date?->format('Y-m-d') }}" readonly data-member-id="{{ $member->id }}" data-type="{{ $type }}">
                                        @if($type === 'kip_linia')
                                        <div class="mt-1 text-xs flex flex-col gap-0.5">
                                            <div class="flex gap-1">
                                                <input type="time" name="normatives[{{ $member->id }}][{{ $type }}][start_time]" value="{{ $norm?->start_time }}" placeholder="Вр.нач" class="border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white p-0.5 w-28 text-xs rounded focus:outline-none focus:ring-1 focus:ring-orange-500 font-mono">
                                                <input type="text" name="normatives[{{ $member->id }}][{{ $type }}][start_station]" value="{{ $norm?->start_station }}" placeholder="Ст.нач" class="border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white p-0.5 w-36 text-xs rounded focus:outline-none focus:ring-1 focus:ring-orange-500">
                                            </div>
                                            <div class="flex gap-1">
                                                <input type="time" name="normatives[{{ $member->id }}][{{ $type }}][end_time]" value="{{ $norm?->end_time }}" placeholder="Вр.кон" class="border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white p-0.5 w-28 text-xs rounded focus:outline-none focus:ring-1 focus:ring-orange-500 font-mono">
                                                <input type="text" name="normatives[{{ $member->id }}][{{ $type }}][end_station]" value="{{ $norm?->end_station }}" placeholder="Ст.кон" class="border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white p-0.5 w-36 text-xs rounded focus:outline-none focus:ring-1 focus:ring-orange-500">
                                            </div>
                                        </div>
                                        @endif
                                        @if($type === 'kip_manevry')
                                        <div class="mt-1 text-xs flex gap-1">
                                            <input type="time" name="normatives[{{ $member->id }}][{{ $type }}][start_time]" value="{{ $norm?->start_time }}" placeholder="нач.КИП" class="border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white p-0.5 w-24 text-xs rounded focus:outline-none focus:ring-1 focus:ring-orange-500 font-mono">
                                            <input type="time" name="normatives[{{ $member->id }}][{{ $type }}][end_time]" value="{{ $norm?->end_time }}" placeholder="кон.КИП" class="border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white p-0.5 w-24 text-xs rounded focus:outline-none focus:ring-1 focus:ring-orange-500 font-mono">
                                            <input type="text" name="normatives[{{ $member->id }}][{{ $type }}][maneuver_alternation]" value="{{ $norm?->details['alternation'] ?? '' }}" placeholder="из/в" class="border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white p-0.5 w-14 text-xs rounded focus:outline-none focus:ring-1 focus:ring-orange-500">
                                        </div>
                                        @endif
                                        @if($type === 'kip_scep')
                                        <div class="mt-1 text-xs">
                                            <input type="text" name="normatives[{{ $member->id }}][{{ $type }}][scep_status]" value="{{ $norm?->details['status'] ?? '' }}" placeholder="всп/неиспр" class="border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white p-0.5 w-16 text-xs rounded focus:outline-none focus:ring-1 focus:ring-orange-500">
                                        </div>
                                        @endif
                                    </td>
                                    <td class="p-2 text-gray-900 dark:text-gray-200">
                                        <textarea name="normatives[{{ $member->id }}][{{ $type }}][remarks]" class="border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white p-0.5 text-xs w-28 rounded focus:outline-none focus:ring-1 focus:ring-orange-500" rows="1">{{ $norm?->remarks }}</textarea>
                                        @if($type === 'kip_linia')
                                        <button type="button" onclick="addKipOccurrence({{ $member->id }})" class="text-blue-600 dark:text-blue-400 text-xs block mt-0.5 hover:underline">+ ещё</button>
                                        <div id="occ-{{ $member->id }}" class="text-xs"></div>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    </div>
                </div>
            @endforeach
        </div>

        <button type="submit" class="bg-orange-500 text-white px-6 py-2 rounded-xl mt-4">Сохранить нормативы</button>
    </form>
</div>

<script>
const normativePeriods = {
    kip_linia: {
        'bk': {{ $setting->kip_linia_bk_months ?? 4 }},
        '3': {{ $setting->kip_linia_3_months ?? 3 }},
        '2': {{ $setting->kip_linia_2_months ?? 4 }},
        '1': {{ $setting->kip_linia_1_months ?? 4 }}
    },
    kip_manevry: {{ $setting->kip_manevry_months ?? 6 }},
    kip_podem: {{ $setting->kip_podem_months ?? 12 }},
    kip_kru: {{ $setting->kip_kru_months ?? 12 }},
    kip_ars_r: {{ $setting->kip_ars_r_months ?? 12 }},
    kip_pnevmatika: {{ $setting->kip_pnevmatika_months ?? 12 }},
    kip_scep: {{ $setting->kip_scep_months ?? 12 }},
    atz: {{ $setting->atz_months ?? 12 }},
    atz_line: {{ $setting->atz_line_months ?? 12 }}
};

function calculateNextDate(lastDateStr, classVal, type) {
    if (!lastDateStr || !classVal) return '';
    const last = new Date(lastDateStr);
    let months = 12;
    if (type === 'kip_linia') {
        months = normativePeriods.kip_linia[classVal] || 4;
    } else if (type === 'kip_manevry') {
        months = normativePeriods.kip_manevry || 6;
    } else if (type === 'kip_podem') {
        months = normativePeriods.kip_podem || 12;
    } else if (type === 'kip_kru') {
        months = normativePeriods.kip_kru || 12;
    } else if (type === 'kip_ars_r') {
        months = normativePeriods.kip_ars_r || 12;
    } else if (type === 'kip_pnevmatika') {
        months = normativePeriods.kip_pnevmatika || 12;
    } else if (type === 'kip_scep') {
        months = normativePeriods.kip_scep || 12;
    } else if (type === 'atz') {
        months = normativePeriods.atz || 12;
    } else if (type === 'atz_line') {
        months = normativePeriods.atz_line || 12;
    }
    last.setMonth(last.getMonth() + months);
    return last.toISOString().split('T')[0];
}

function showTab(tab) {
    document.querySelectorAll('.tab-content').forEach(el => el.classList.add('hidden'));
    document.getElementById('tab-' + tab).classList.remove('hidden');
    document.querySelectorAll('.tab-button').forEach(el => {
        el.classList.remove('border-orange-500', 'text-orange-500');
        el.classList.add('border-transparent');
    });
    const active = document.querySelector(`[data-tab="${tab}"]`);
    if (active) {
        active.classList.remove('border-transparent');
        active.classList.add('border-orange-500', 'text-orange-500');
    }
}

document.addEventListener('DOMContentLoaded', function() {
    // Attach listeners for class change and last date change
    document.querySelectorAll('.class-select').forEach(function(select) {
        select.addEventListener('change', function() {
            const memberId = this.dataset.memberId;
            const type = this.dataset.type;
            const row = this.closest('tr');
            const lastInput = row.querySelector('.last-date');
            const nextInput = row.querySelector('.next-date');
            if (lastInput && nextInput) {
                const newNext = calculateNextDate(lastInput.value, this.value, type);
                nextInput.value = newNext;
            }
            // Sync class to other tabs for same member
            document.querySelectorAll(`.class-select[data-member-id="${memberId}"]`).forEach(function(other) {
                if (other !== select) {
                    other.value = this.value;
                    // recalc other if has last
                    const otherRow = other.closest('tr');
                    const otherLast = otherRow ? otherRow.querySelector('.last-date') : null;
                    const otherNext = otherRow ? otherRow.querySelector('.next-date') : null;
                    if (otherLast && otherNext && otherLast.value) {
                        otherNext.value = calculateNextDate(otherLast.value, this.value, other.dataset.type);
                    }
                }
            }.bind(this));
        });
    });

    document.querySelectorAll('.last-date').forEach(function(input) {
        input.addEventListener('change', function() {
            const row = this.closest('tr');
            const classSelect = row.querySelector('.class-select');
            const nextInput = row.querySelector('.next-date');
            if (classSelect && nextInput) {
                nextInput.value = calculateNextDate(this.value, classSelect.value, classSelect.dataset.type);
            }
        });
    });
});

function addKipOccurrence(memberId) {
    const container = document.getElementById('occ-' + memberId);
    if (!container) return;
    const idx = container.children.length;
    const div = document.createElement('div');
    div.className = 'flex gap-1 mt-1';
    div.innerHTML = `
        <input type="time" name="normatives[${memberId}][kip_linia][occurrences][${idx}][start_time]" placeholder="Вр.нач" class="border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white p-0.5 w-20 text-xs font-mono rounded">
        <input type="text" name="normatives[${memberId}][kip_linia][occurrences][${idx}][start_station]" placeholder="Ст.нач" class="border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white p-0.5 w-24 text-xs rounded">
        <input type="time" name="normatives[${memberId}][kip_linia][occurrences][${idx}][end_time]" placeholder="Вр.кон" class="border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white p-0.5 w-20 text-xs font-mono rounded">
        <input type="text" name="normatives[${memberId}][kip_linia][occurrences][${idx}][end_station]" placeholder="Ст.кон" class="border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white p-0.5 w-24 text-xs rounded">
        <button type="button" onclick="this.parentNode.remove()" class="text-red-500">×</button>
    `;
    container.appendChild(div);
}
</script>
@endsection