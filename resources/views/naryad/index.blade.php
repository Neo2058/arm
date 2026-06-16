@extends('naryad.layouts.naryad')

@section('naryad-content')
    <div class="max-w-3xl mx-auto py-12 text-center">
        <div class="text-4xl mb-3">📋</div>
        <h1 class="text-2xl font-semibold mb-2">Сетка наряда загружается...</h1>
        <p class="text-orange-600 dark:text-orange-400">Если не загрузилось автоматически — обновите страницу или выберите раздел в левом меню.</p>
        <div class="mt-6 text-xs text-orange-500 dark:text-orange-400">
            Месяц: <strong>{{ $currentMonth }}</strong><br>
            Роль: Нарядчик (уникальный интерфейс без основного сайдбара)
        </div>
    </div>
@endsection