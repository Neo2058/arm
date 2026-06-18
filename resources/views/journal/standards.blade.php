@extends('teaching.layouts.index')

@section('content')
<div class="max-w-7xl mx-auto p-6">
    <h1 class="text-2xl font-bold mb-6">Нормативы - Колонна {{ $column }}</h1>

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
                    <table class="min-w-full text-sm bg-white dark:bg-gray-800 rounded-xl overflow-hidden">
                        <thead>
                            <tr class="bg-gray-100 dark:bg-gray-700">
                                <th class="p-2 text-left">ФИО</th>
                                <th class="p-2">Дата проведения</th>
                                <th class="p-2">Дата следующего</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($crew as $member)
                                @php 
                                    $norm = $normatives->get($member->id, collect())->firstWhere('type', $type);
                                @endphp
                                <tr class="border-t">
                                    <td class="p-2">{{ $member->name }}</td>
                                    <td class="p-2">
                                        <input type="date" name="normatives[{{ $member->id }}][{{ $type }}][last_date]" 
                                               value="{{ $norm?->last_date?->format('Y-m-d') }}" class="border p-1 text-sm w-full">
                                    </td>
                                    <td class="p-2">
                                        <input type="date" name="normatives[{{ $member->id }}][{{ $type }}][next_date]" 
                                               value="{{ $norm?->next_date?->format('Y-m-d') }}" class="border p-1 text-sm w-full bg-gray-50" readonly>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endforeach
        </div>

        <button type="submit" class="bg-orange-500 text-white px-6 py-2 rounded-xl mt-4">Сохранить нормативы</button>
    </form>
</div>

<script>
function showTab(tab) {
    document.querySelectorAll('.tab-content').forEach(el => el.classList.add('hidden'));
    document.getElementById('tab-' + tab).classList.remove('hidden');
    document.querySelectorAll('.tab-button').forEach(el => el.classList.remove('border-orange-500', 'text-orange-500'));
    document.querySelector(`[data-tab="${tab}"]`).classList.add('border-orange-500', 'text-orange-500');
}
</script>
@endsection