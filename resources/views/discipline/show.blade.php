@extends('teaching.layouts.index')
@section('content')
<div class="max-w-4xl mx-auto p-6 pb-28 bg-[#0b1018] text-gray-100 rounded-2xl space-y-5">
    <a class="underline" href="{{ auth()->user()->isInstructor() ? route('discipline.index') : route('filament.discipline.pages.discipline-monitor') }}">К списку поручений</a>
    <h1 class="text-2xl font-bold">{{ $assignment->card->title }}</h1>
    <p>Исполнитель: {{ $assignment->assignee->name }} · Руководитель: {{ $assignment->card->author->name }}</p>
    <p>Срок: {{ $assignment->card->due_date->format('d.m.Y') }} · {{ \App\Models\DisciplineAssignment::LABELS[$assignment->status] }}</p>
    @if($assignment->is_overdue)<p class="text-red-600 font-bold">Просрочено</p>@endif
    <p class="whitespace-pre-wrap">{{ $assignment->card->instructions }}</p>
    <p>Обязательно: {{ $assignment->card->require_response ? 'текстовый ответ; ' : '' }}{{ $assignment->card->require_report_file ? 'файл отчёта; ' : '' }}{{ $assignment->card->require_explanation_file ? 'файл объяснения; ' : '' }}{{ !$assignment->card->require_response && !$assignment->card->require_report_file && !$assignment->card->require_explanation_file ? 'подтверждение выполнения' : '' }}</p>
    @if(session('success'))<p role="status" class="text-green-700">{{ session('success') }}</p>@endif
    @if($errors->any())<div role="alert" class="text-red-600">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
    @if(auth()->user()->isInstructor() && in_array($assignment->status, ['assigned', 'needs_revision']))
        <form method="POST" enctype="multipart/form-data" action="{{ route('discipline.submit', $assignment->id) }}" class="space-y-4 border rounded-xl p-4">
            @csrf
            <input type="hidden" name="operation_id" value="{{ old('operation_id', (string) \Illuminate\Support\Str::uuid()) }}">
            <input type="hidden" name="version" value="{{ old('version', $assignment->version) }}">
            <label class="block">Ответ<textarea name="response" rows="6" maxlength="20000" class="block w-full rounded-lg text-gray-900" @required($assignment->card->require_response)>{{ old('response') }}</textarea></label>
            <p>PDF, JPEG, PNG. До 10 МиБ на файл, всего до пяти файлов и 25 МиБ. Все файлы проверяются антивирусом.</p>
            @foreach(['report' => 'Файлы отчёта', 'explanation' => 'Файлы объяснения', 'other' => 'Другие приложения'] as $category => $label)
                <label class="block">{{ $label }}<input type="file" name="{{ $category }}_files[]" multiple accept=".pdf,.jpg,.jpeg,.png" class="block" @required(($category === 'report' && $assignment->card->require_report_file) || ($category === 'explanation' && $assignment->card->require_explanation_file))></label>
            @endforeach
            <button type="submit" class="px-4 py-2 bg-orange-600 text-white rounded-lg">Отправить на проверку</button>
        </form>
    @endif
    <h2 class="text-xl font-bold">История отчётов</h2>
    <p class="text-sm">Часовой пояс: {{ config('discipline.timezone') }}</p>
    @forelse($assignment->reports->sortByDesc('attempt') as $report)
        <article class="border rounded-xl p-4 space-y-3">
            <h3 class="font-bold">Попытка №{{ $report->attempt }} · {{ $report->submitted_at->timezone(config('discipline.timezone'))->format('d.m.Y H:i:s') }}</h3>
            <p class="whitespace-pre-wrap">{{ $report->response }}</p>
            @foreach($report->files as $file)<p><a class="underline" href="{{ route('discipline.files', $file->id) }}">{{ $file->name }}</a> · {{ ['report' => 'отчёт', 'explanation' => 'объяснение', 'other' => 'приложение'][$file->category] }}</p>@endforeach
            @if($report->decision)
                <p class="font-semibold">{{ $report->decision === 'accepted' ? 'Принят' : 'Возвращён на доработку' }} · {{ $report->reviewer?->name }} · {{ $report->reviewed_at->timezone(config('discipline.timezone'))->format('d.m.Y H:i:s') }}</p>
                <p class="whitespace-pre-wrap">{{ $report->review_comment }}</p>
            @elseif(auth()->user()->canManageDiscipline() && $assignment->status === 'in_review')
                <form method="POST" action="{{ route('discipline.review', $assignment->id) }}" class="space-y-3">
                    @csrf
                    <input type="hidden" name="operation_id" value="{{ old('operation_id', (string) \Illuminate\Support\Str::uuid()) }}">
                    <input type="hidden" name="version" value="{{ old('version', $assignment->version) }}">
                    <input type="hidden" name="report_id" value="{{ $report->id }}">
                    <label class="block">Комментарий (обязателен при возврате)<textarea name="comment" maxlength="20000" rows="3" class="block w-full rounded-lg text-gray-900">{{ old('comment') }}</textarea></label>
                    <button type="submit" name="decision" value="accepted" class="px-4 py-2 bg-green-700 text-white rounded-lg">Принять</button>
                    <button type="submit" name="decision" value="returned" class="px-4 py-2 bg-orange-600 text-white rounded-lg">Вернуть</button>
                </form>
            @endif
        </article>
    @empty<p>Отчётов пока нет.</p>@endforelse
</div>
@endsection
