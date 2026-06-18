@extends('teaching.layouts.index')

@section('content')
<div class="max-w-4xl mx-auto p-6">
    <h1 class="text-2xl font-bold mb-6">Настройка нормативов</h1>

    @if(session('success'))
        <div class="bg-green-100 p-3 mb-4 rounded">{{ session('success') }}</div>
    @endif

    <form action="{{ route('journal.settings.update') }}" method="POST" class="space-y-6">
        @csrf

        <div class="bg-white dark:bg-gray-800 p-6 rounded-xl border">
            <h3 class="font-semibold mb-4 text-orange-500">КИП Линия</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label>б/к (1 раз в ... месяцев)</label>
                    <input type="number" name="kip_linia_bk_months" value="{{ $setting->kip_linia_bk_months ?? 4 }}" class="w-full border p-2 rounded">
                </div>
                <div>
                    <label>3-й класс</label>
                    <input type="number" name="kip_linia_3_months" value="{{ $setting->kip_linia_3_months ?? 3 }}" class="w-full border p-2 rounded">
                </div>
                <div>
                    <label>2-й класс</label>
                    <input type="number" name="kip_linia_2_months" value="{{ $setting->kip_linia_2_months ?? 4 }}" class="w-full border p-2 rounded">
                </div>
                <div>
                    <label>1-й класс</label>
                    <input type="number" name="kip_linia_1_months" value="{{ $setting->kip_linia_1_months ?? 4 }}" class="w-full border p-2 rounded">
                </div>
            </div>
        </div>

        <div class="bg-white dark:bg-gray-800 p-6 rounded-xl border">
            <h3 class="font-semibold mb-4 text-orange-500">КИП Манёвры</h3>
            <div>
                <label>Период (месяцев)</label>
                <input type="number" name="kip_manevry_months" value="{{ $setting->kip_manevry_months ?? 6 }}" class="w-full border p-2 rounded">
            </div>
            <div class="mt-2">
                <label>
                    <input type="checkbox" name="kip_manevry_alternation" value="1" {{ ($setting->kip_manevry_alternation ?? true) ? 'checked' : '' }}>
                    Контролировать чередование из/в тупик
                </label>
            </div>
        </div>

        <div class="bg-white dark:bg-gray-800 p-6 rounded-xl border">
            <h3 class="font-semibold mb-4 text-orange-500">КИП Подъём</h3>
            <div>
                <label>Период (месяцев)</label>
                <input type="number" name="kip_podem_months" value="{{ $setting->kip_podem_months ?? 12 }}" class="w-full border p-2 rounded">
            </div>
        </div>

        <div class="bg-white dark:bg-gray-800 p-6 rounded-xl border">
            <h3 class="font-semibold mb-4 text-orange-500">Другие КИП и АТЗ (уточняемые)</h3>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                @foreach(['kip_kru' => 'КИП КРУ', 'kip_ars_r' => 'КИП АРС-Р', 'kip_pnevmatika' => 'КИП Пневматика', 'kip_scep' => 'КИП Сцеп', 'atz' => 'АТЗ', 'atz_line' => 'АТЗ на Линии'] as $key => $label)
                    <div>
                        <label>{{ $label }} (месяцев)</label>
                        <input type="number" name="{{ $key }}_months" value="{{ $setting->{$key.'_months'} ?? 12 }}" class="w-full border p-2 rounded">
                    </div>
                @endforeach
            </div>
        </div>

        <!-- Crew marks table moved here from Нормативы -->
        <div class="bg-white dark:bg-gray-800 rounded-xl p-4 mb-6 overflow-x-auto">
            <h3 class="font-semibold mb-4 text-orange-500">Пометки экипажа (М Т6 П) и Класс</h3>
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="border-b">
                        <th class="p-2 text-left">ФИО</th>
                        <th class="p-2">M</th>
                        <th class="p-2">T6</th>
                        <th class="p-2">П</th>
                        <th class="p-2">Класс</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($crew ?? [] as $member)
                        @php $p = $member->profile; @endphp
                        <tr class="border-b">
                            <td class="p-2">{{ $member->name }}</td>
                            <td class="p-2 text-center">
                                <input type="checkbox" name="crew[{{ $member->id }}][is_maneuver]" {{ $p?->is_maneuver ? 'checked' : '' }}>
                            </td>
                            <td class="p-2 text-center">
                                <input type="checkbox" name="crew[{{ $member->id }}][is_t6]" {{ $p?->is_t6 ? 'checked' : '' }}>
                            </td>
                            <td class="p-2 text-center">
                                <input type="checkbox" name="crew[{{ $member->id }}][is_pomoshnik]" {{ $p?->is_pomoshnik ? 'checked' : '' }}>
                            </td>
                            <td class="p-2">
                                <select name="crew[{{ $member->id }}][class]" class="border p-1 text-xs">
                                    @foreach(['bk' => 'б/к', '3' => '3-й', '2' => '2-й', '1' => '1-й'] as $val => $label)
                                        <option value="{{ $val }}" {{ ($p?->normative_class ?? 'bk') == $val ? 'selected' : '' }}>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <button type="submit" class="bg-orange-500 text-white px-6 py-2 rounded-xl">Сохранить настройки</button>
    </form>
</div>
@endsection