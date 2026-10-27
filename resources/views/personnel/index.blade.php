@extends('teaching.layouts.index')

@section('content')
<div class="max-w-6xl mx-auto p-6">
    <h1 class="text-2xl font-bold mb-4">Картотека машинистов</h1>
    @if($isInstructor)
        <p class="mb-4">Колонна: {{ $column ?? 'не назначена' }}. Показаны только сотрудники вашей колонны.</p>
        @if(!$column)<p class="mb-4">Обратитесь к администратору для назначения колонны в профиле.</p>@endif
    @endif
    <form method="GET" action="{{ route('personnel.index') }}" class="flex flex-wrap gap-3 mb-4">
        <input name="q" value="{{ $q }}" maxlength="100" placeholder="ФИО, табельный, бригада" aria-label="Поиск по картотеке" class="border rounded-xl px-3 py-2 text-gray-900">
        <label class="flex items-center gap-2"><input type="checkbox" name="active" value="1" @checked($active)> Только работающие</label>
        <button class="bg-orange-600 text-white rounded-xl px-4 py-2">Найти</button>
    </form>
    <p class="mb-3">Найдено: {{ $people->total() }}</p>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead><tr class="text-left border-b"><th class="p-2">Табельный</th><th class="p-2">ФИО</th><th class="p-2">Колонна</th><th class="p-2">Должность / класс</th><th class="p-2">Бригада</th><th class="p-2">Телефон</th><th class="p-2">Уволен</th></tr></thead>
            <tbody>
            @forelse($people as $person)
                <tr class="border-b">
                    <td class="p-2">{{ $person->tab_number }}</td>
                    <td class="p-2"><a class="text-orange-600 underline" href="{{ route('personnel.show', $person) }}">{{ $person->full_name }}</a></td>
                    <td class="p-2">{{ $person->user?->profile?->column ?? 'Не назначена' }}</td>
                    <td class="p-2">{{ $person->position_code }} / {{ $person->class_code }}</td>
                    <td class="p-2">{{ $person->brigade_code }}</td>
                    <td class="p-2">{{ $person->phone_primary }}</td>
                    <td class="p-2">{{ $person->fired_on?->format('d.m.Y') }}</td>
                </tr>
            @empty
                <tr><td class="p-4" colspan="7">Карточки не найдены.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $people->links() }}</div>
</div>
@endsection
