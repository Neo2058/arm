@extends('teaching.layouts.index')
@section('content')
@php($labels = ['not_submitted' => 'Не внесено', 'pending' => 'На проверке', 'confirmed' => 'Подтверждено', 'returned' => 'Возвращено'])
<div class="max-w-5xl mx-auto space-y-5 text-gray-900 dark:text-white">
<h1 class="text-2xl font-bold">Формуляр</h1>
<p>Внесите запись по заданию. Инструктор проверит её и при необходимости оставит замечание.</p>
<div class="flex gap-4"><a href="{{ route('formular.index') }}">Мои записи</a>
@if($actor->isInstructor())<a href="{{ route('formular.index', ['review' => 1]) }}">Проверка колонны</a>@endif</div>
@if(session('success'))<p role="status">{{ session('success') }}</p>@endif
@if($errors->any())<div role="alert">{{ $errors->first() }} Введённый текст сохранён ниже.</div>@endif
@if($review)
<p>Колонна {{ $data['column'] }}. Всего: {{ $data['stats']['total'] }}.
@foreach($labels as $key => $label) {{ $label }}: {{ $data['stats'][$key] }}. @endforeach</p>
<form method="GET" class="flex flex-wrap gap-3">
<input type="hidden" name="review" value="1">
<select name="user_id" aria-label="Машинист" class="text-black"><option value="">Все машинисты</option>@foreach($data['crew'] as $member)<option value="{{ $member->id }}" @selected(request('user_id') == $member->id)>{{ $member->name }}</option>@endforeach</select>
<select name="task_id" aria-label="Задание" class="text-black"><option value="">Все задания</option>@foreach($data['tasks'] as $task)<option value="{{ $task->id }}" @selected(request('task_id') == $task->id)>{{ $task->title }}</option>@endforeach</select>
<select name="status" aria-label="Статус" class="text-black"><option value="">Все статусы</option>@foreach($labels as $key => $label)<option value="{{ $key }}" @selected(request('status') === $key)>{{ $label }}</option>@endforeach</select>
<button>Показать</button></form>
@forelse($data['records'] as $record)
<section class="border rounded-xl p-4 space-y-3"><h2 class="font-bold">{{ $record->name }} · {{ $record->title }}</h2>
<p>{{ $labels[$record->status ?? 'not_submitted'] }} · {{ $record->completed_at }}</p>
<p class="whitespace-pre-wrap">{{ $record->entry_text }}</p>
@if($record->review_comment)<p>Замечание: {{ $record->review_comment }}</p>@endif
@if($record->status === 'pending')
<form method="POST" action="{{ route('formular.review', $record->id) }}" class="space-y-3">@csrf
<input type="hidden" name="operation_id" value="{{ \Illuminate\Support\Str::uuid() }}"><input type="hidden" name="version" value="{{ $record->version }}">
<textarea name="review_comment" maxlength="2000" aria-label="Замечание" placeholder="Для возврата укажите замечание" class="block w-full text-black">{{ old('review_comment') }}</textarea>
<button name="decision" value="confirmed" class="px-4 py-2 bg-green-700 text-white rounded">Подтвердить</button>
<button name="decision" value="returned" class="px-4 py-2 bg-orange-700 text-white rounded">Вернуть</button></form>
@endif</section>
@empty<p>Записей по выбранным условиям нет.</p>@endforelse
<div class="flex gap-4">@if($data['pagination']['page'] > 1)<a href="{{ request()->fullUrlWithQuery(['page' => $data['pagination']['page'] - 1]) }}">Назад</a>@endif
@if($data['pagination']['next_page'])<a href="{{ request()->fullUrlWithQuery(['page' => $data['pagination']['next_page']]) }}">Далее</a>@endif</div>
@else
@forelse($data['tasks'] as $task)
@php($log = $task['log'])
<section class="border rounded-xl p-4 space-y-3"><h2 class="font-bold">{{ $task['title'] }}</h2><p>{{ $task['description'] }}</p>
<p>{{ $labels[$log['status'] ?? 'not_submitted'] }}</p>
@if($log)<p class="whitespace-pre-wrap">{{ $log['entry_text'] }}</p>@if($log['review_comment'])<p>Замечание: {{ $log['review_comment'] }}</p>@endif @endif
@if(!$log || $log['status'] === 'returned')
<form method="POST" action="{{ route('formular.submit', $task['id']) }}" class="space-y-3">@csrf
<input type="hidden" name="operation_id" value="{{ old('task_id') == $task['id'] ? old('operation_id', (string) \Illuminate\Support\Str::uuid()) : \Illuminate\Support\Str::uuid() }}">
<input type="hidden" name="task_id" value="{{ $task['id'] }}"><input type="hidden" name="version" value="{{ $log['version'] ?? 0 }}">
<textarea name="entry_text" aria-label="Текст записи" maxlength="10000" required class="block w-full text-black">{{ old('task_id') == $task['id'] ? old('entry_text') : ($log['entry_text'] ?? '') }}</textarea>
<button class="px-4 py-2 bg-blue-700 text-white rounded">Отправить на проверку</button></form>
@endif</section>
@empty<p>Нет активных заданий.</p>@endforelse
@endif
</div>
@endsection
