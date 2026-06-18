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
                <div class="md:col-span-2">
                    <label>Прибавлять к дате (месяцев)</label>
                    <select name="kip_linia_add_months" class="w-full border p-2 rounded">
                        <option value="4" {{ ($setting->kip_linia_add_months ?? 4) == 4 ? 'selected' : '' }}>4 месяца</option>
                        <option value="1" {{ ($setting->kip_linia_add_months ?? 4) == 1 ? 'selected' : '' }}>1 месяц</option>
                    </select>
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

        <button type="submit" class="bg-orange-500 text-white px-6 py-2 rounded-xl">Сохранить настройки</button>
    </form>
</div>
@endsection