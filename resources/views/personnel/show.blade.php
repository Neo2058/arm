@extends('teaching.layouts.index')

@section('content')
<div class="max-w-4xl mx-auto p-6">
    <a class="text-orange-600 underline" href="{{ route('personnel.index') }}">← Картотека</a>
    <h1 class="text-2xl font-bold my-4">{{ $person->full_name }}</h1>
    <p class="mb-4">Табельный: {{ $person->tab_number }} · Колонна: {{ $person->user?->profile?->column ?? 'Не назначена' }}</p>
    @if($person->fired_on)<p class="mb-4">Дата увольнения: {{ $person->fired_on->format('d.m.Y') }}</p>@endif
    @if(session('success'))<p class="mb-4 text-green-700">{{ session('success') }}</p>@endif
    @if($errors->any())<ul class="mb-4 text-red-600">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>@endif
    <form action="{{ route('personnel.update', $person) }}" method="POST">
        @csrf
        @method('PATCH')
        <fieldset @disabled(!$canEdit) class="grid grid-cols-1 md:grid-cols-2 gap-4">
            @foreach(['full_name' => ['ФИО', 255], 'position_code' => ['Должность', 8], 'class_code' => ['Класс', 4], 'phone_primary' => ['Телефон', 16], 'phone_secondary' => ['Дополнительный телефон', 16], 'brigade_code' => ['Бригада', 8], 'shop_code' => ['Цех', 8], 'roster_number' => ['Номер в списке', 8], 'category_code' => ['Категория', 4], 'premium_flag' => ['Отметка премии', 4], 'main_tab_number' => ['Основной табельный', 8], 'depo_code' => ['Депо', 8]] as $field => [$label, $length])
                <label>{{ $label }}<input name="{{ $field }}" maxlength="{{ $length }}" value="{{ old($field, $person->$field) }}" @required($field === 'full_name') class="block w-full border rounded-xl p-2 text-gray-900"></label>
            @endforeach
            @if($canAssignColumn)
                <label>Колонна<input name="column" maxlength="32" value="{{ old('column', $person->user?->profile?->column) }}" class="block w-full border rounded-xl p-2 text-gray-900"></label>
            @endif
            @foreach(['hired_on' => 'Дата приёма', 'med_from' => 'Медкомиссия с', 'med_to' => 'Медкомиссия до', 'seniority_on' => 'Начало выслуги машиниста', 'assistant_seniority_on' => 'Начало выслуги помощника', 'brigadier_from' => 'Бригадир с', 'brigadier_to' => 'Бригадир до'] as $field => $label)
                <label>{{ $label }}<input type="date" name="{{ $field }}" value="{{ old($field, $person->$field?->format('Y-m-d')) }}" class="block w-full border rounded-xl p-2 text-gray-900"></label>
            @endforeach
            <label>Выслуга помощника, лет<input type="number" name="assistant_seniority_years" min="0" max="999" required value="{{ old('assistant_seniority_years', $person->assistant_seniority_years) }}" class="block w-full border rounded-xl p-2 text-gray-900"></label>
            @foreach(['early_windows' => 'Ранние заступления', 'late_windows' => 'Поздние окончания'] as $field => $label)
                <div>
                    <h2 class="font-semibold mb-2">{{ $label }}</h2>
                    @for($i = 0; $i < 3; $i++)
                        <div class="flex gap-2 mb-2">
                            <label>Время {{ $i + 1 }}<input name="{{ $field }}[{{ $i }}][time]" maxlength="5" placeholder="ЧЧ.ММ" value="{{ old($field.'.'.$i.'.time', $person->$field[$i]['time'] ?? '') }}" class="block w-full border rounded-xl p-2 text-gray-900"></label>
                            <label>Основание<input name="{{ $field }}[{{ $i }}][reason]" maxlength="8" value="{{ old($field.'.'.$i.'.reason', $person->$field[$i]['reason'] ?? '') }}" class="block w-full border rounded-xl p-2 text-gray-900"></label>
                        </div>
                    @endfor
                </div>
            @endforeach
            <label><input type="hidden" name="is_brigadier" value="0"><input type="checkbox" name="is_brigadier" value="1" @checked(old('is_brigadier', $person->is_brigadier))> Бригадир</label>
        </fieldset>
        @if($canEdit)<button class="mt-5 bg-orange-600 text-white rounded-xl px-5 py-2">Сохранить</button>@endif
    </form>
    @if($person->seniority_on)
        <p class="mt-5">Выслуга машиниста на сегодня: {{ max(0, (int) $person->seniority_on->diffInYears(now())) }} полных лет.</p>
    @endif
    <dl class="grid grid-cols-2 gap-3 mt-6 text-sm">
        <dt>Начало стажа</dt><dd>{{ $person->seniority_on?->format('d.m.Y') ?? '—' }}</dd>
        <dt>Стаж помощника с</dt><dd>{{ $person->assistant_seniority_on?->format('d.m.Y') ?? '—' }}</dd>
        <dt>Цех</dt><dd>{{ $person->shop_code ?? '—' }}</dd>
        <dt>Депо</dt><dd>{{ $person->depo_code ?? '—' }}</dd>
    </dl>
    @if($person->source_data)
        <details class="mt-6">
            <summary class="font-semibold cursor-pointer">Все исходные поля старой картотеки</summary>
            <dl class="grid grid-cols-2 gap-2 mt-3 text-sm">
                @foreach($person->source_data as $field => $value)
                    <dt>{{ $field }}</dt><dd>{{ $value === null || $value === '' ? '—' : $value }}</dd>
                @endforeach
            </dl>
        </details>
    @endif
</div>
@endsection
