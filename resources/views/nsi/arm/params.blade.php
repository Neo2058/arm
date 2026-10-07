@extends('nsi.layouts.nsi')
@section('content')
    <h1 class="text-2xl font-bold mb-1">Общие параметры</h1>
    <p class="text-sm text-orange-600 mb-6">NRDPAR пишется в JSON <code>naryad_norms.planir</code> слиянием. Ночные коды всегда включают 5+. Режим интервала Печатников — формула часы×2.</p>

    <form method="POST" action="{{ route('nsi.params.update') }}" class="bg-white dark:bg-[#0b1018] border rounded-3xl p-6 grid md:grid-cols-2 gap-4 text-sm">
        @csrf
        @method('PUT')
        <label>Название организации
            <input name="org" value="{{ $planir['org'] ?? '' }}" class="block w-full border rounded-2xl px-3 py-2">
        </label>
        <label>Начальник
            <input name="chief" value="{{ $planir['chief'] ?? '' }}" class="block w-full border rounded-2xl px-3 py-2">
        </label>
        <label>Зам. по эксплуатации
            <input name="deputy_ops" value="{{ $planir['deputy_ops'] ?? '' }}" class="block w-full border rounded-2xl px-3 py-2">
        </label>
        <label>Председатель профкома
            <input name="union_chair" value="{{ $planir['union_chair'] ?? '' }}" class="block w-full border rounded-2xl px-3 py-2">
        </label>
        <label>Вечер с
            <input name="evening_from" type="number" step="0.01" value="{{ $planir['evening_from'] ?? '' }}" class="block w-full border rounded-2xl px-3 py-2">
        </label>
        <label>Вечер по
            <input name="evening_to" type="number" step="0.01" value="{{ $planir['evening_to'] ?? '' }}" class="block w-full border rounded-2xl px-3 py-2">
        </label>
        <label>Ночь с
            <input name="night_from" type="number" step="0.01" value="{{ $planir['night_from'] ?? '' }}" class="block w-full border rounded-2xl px-3 py-2">
        </label>
        <label>Ночь по
            <input name="night_to" type="number" step="0.01" value="{{ $planir['night_to'] ?? '' }}" class="block w-full border rounded-2xl px-3 py-2">
        </label>
        <label>Мин. номер маршрута
            <input name="route_from" type="number" value="{{ $planir['route_from'] ?? 1 }}" class="block w-full border rounded-2xl px-3 py-2">
        </label>
        <label>Макс. номер маршрута
            <input name="route_to" type="number" value="{{ $planir['route_to'] ?? 35 }}" class="block w-full border rounded-2xl px-3 py-2">
        </label>
        <label>Аванс
            <input name="advance" value="{{ $planir['advance'] ?? '' }}" class="block w-full border rounded-2xl px-3 py-2">
        </label>
        <label>% бригадирских
            <input name="brigadier_percent" type="number" step="0.01" value="{{ $planir['brigadier_percent'] ?? '' }}" class="block w-full border rounded-2xl px-3 py-2">
        </label>
        <label>Норма часов техучёбы
            <input name="tech_hours" type="number" step="0.01" value="{{ $planir['tech_hours'] ?? '' }}" class="block w-full border rounded-2xl px-3 py-2">
        </label>
        <label>Код оплаты совместителей
            <input name="compat_pay_code" value="{{ $planir['compat_pay_code'] ?? ($planir['shop_combined'] ?? '') }}" class="block w-full border rounded-2xl px-3 py-2">
        </label>
        <label>Режим интервала
            <select name="interval_mode" class="block w-full border rounded-2xl px-3 py-2">
                <option value="formula" @selected(($planir['interval_mode'] ?? 'formula') === 'formula')>формула часы×2 (NR_12 −)</option>
                <option value="fixed" @selected(($planir['interval_mode'] ?? '') === 'fixed')>фиксированные 12/42/66 (NR_12 +)</option>
            </select>
        </label>
        <label>Линия (лиц на смену)
            <select name="line_mode" class="block w-full border rounded-2xl px-3 py-2">
                <option value="1" @selected((int) ($planir['line_mode'] ?? 1) === 1)>1 (Печатники)</option>
                <option value="2" @selected((int) ($planir['line_mode'] ?? 1) === 2)>2</option>
            </select>
        </label>
        <label>Допуск сверх NRCHAS, ч
            <input name="norm_extra_hours" type="number" step="0.01" value="{{ $planir['norm_extra_hours'] ?? 40 }}" class="block w-full border rounded-2xl px-3 py-2">
        </label>
        <label>Макс. дней без выходного
            <input name="max_days_without_rest" type="number" value="{{ $planir['max_days_without_rest'] ?? 12 }}" class="block w-full border rounded-2xl px-3 py-2">
        </label>
        <label>Продолж. 1 выходного, ч
            <input name="rest_day_hours" type="number" value="{{ $planir['rest_day_hours'] ?? 42 }}" class="block w-full border rounded-2xl px-3 py-2">
        </label>
        <label>Продолж. 2 выходных, ч
            <input name="two_rest_days_hours" type="number" value="{{ $planir['two_rest_days_hours'] ?? 66 }}" class="block w-full border rounded-2xl px-3 py-2">
        </label>
        <label>Мин. дней между выходными
            <input name="min_days_between_rests" type="number" value="{{ $planir['min_days_between_rests'] ?? 3 }}" class="block w-full border rounded-2xl px-3 py-2">
        </label>
        <label>NR_kint, ч
            <input name="short_interval_hours" type="number" step="0.01" value="{{ $planir['short_interval_hours'] ?? 0 }}" class="block w-full border rounded-2xl px-3 py-2">
        </label>
        <label class="md:col-span-2 inline-flex items-center gap-2 mt-2">
            <input type="hidden" name="subtract_holiday_hours" value="0">
            <input type="checkbox" name="subtract_holiday_hours" value="1" @checked($planir['subtract_holiday_hours'] ?? true)>
            Вычитать праздничные из нормы (NR_prazd −)
        </label>
        <p class="md:col-span-2 text-[11px] text-orange-500">Ночные смены PLANIR: {{ implode(', ', $planir['night_shift_codes'] ?? ['3+', '4+', '5+']) }}. Код 5+ при сохранении всегда остаётся.</p>
        <div class="md:col-span-2">
            <button class="px-5 py-2 bg-orange-600 text-white rounded-2xl text-sm font-semibold">Сохранить параметры</button>
        </div>
    </form>
@endsection
