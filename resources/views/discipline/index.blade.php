@extends('teaching.layouts.index')
@section('content')
<div class="max-w-5xl mx-auto p-6 pb-28 bg-[#0b1018] text-gray-100 rounded-2xl space-y-4">
    <a href="{{ route('journal.index') }}" class="underline">Рабочий журнал</a>
    <h1 class="text-2xl font-bold">Исполнительская дисциплина</h1>
    @forelse($assignments as $assignment)
        <a href="{{ route('discipline.show', $assignment->id) }}" class="block border rounded-xl p-4 space-y-2">
            <h2 class="text-lg font-semibold">{{ $assignment->card->title }}</h2>
            <p>Выдал: {{ $assignment->card->author->name }} · Срок: {{ $assignment->card->due_date->format('d.m.Y') }}</p>
            <p>{{ \App\Models\DisciplineAssignment::LABELS[$assignment->status] }} @if($assignment->is_overdue)<strong class="text-red-600">Просрочено</strong>@endif</p>
        </a>
    @empty<p>Назначенных поручений пока нет.</p>@endforelse
    {{ $assignments->links() }}
</div>
@endsection
