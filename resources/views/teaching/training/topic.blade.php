@extends('teaching.layouts.index')

@section('content')
<div class="max-w-4xl mx-auto w-full">
    <a href="{{ route('training.topics') }}" class="text-sm text-orange-500 hover:underline mb-4 inline-block">← Все темы</a>

    <h1 class="text-3xl font-bold mb-2 text-gray-900 dark:text-white">{{ $topic->title }}</h1>
    @if($topic->description)
        <p class="text-gray-600 dark:text-gray-400 mb-8">{{ $topic->description }}</p>
    @endif

    <h2 class="text-xl font-semibold mb-4 text-gray-900 dark:text-white">Материалы</h2>

    @if($materials->isEmpty())
        <div class="bg-white dark:bg-[#0b1018] border border-gray-200 dark:border-white/10 rounded-2xl p-8 text-center">
            <p class="text-gray-500">В этой теме пока нет материалов.</p>
        </div>
    @else
        <div class="space-y-4">
            @foreach($materials as $material)
                @php
                    $route = match($material->type) {
                        'video' => route('training.video', $material),
                        'audio' => route('training.audio', $material),
                        default => '#',
                    };
                @endphp

                <a href="{{ $route }}"
                   class="block bg-white dark:bg-[#0b1018] border border-gray-200 dark:border-white/10 rounded-2xl p-5 hover:shadow transition active:scale-[0.985]">
                    <div class="flex items-start gap-4">
                        <div class="text-2xl">
                            @if($material->type === 'video') 🎥
                            @elseif($material->type === 'audio') 🎧
                            @else 📄
                            @endif
                        </div>
                        <div class="flex-1 min-w-0">
                            <h3 class="font-semibold text-gray-900 dark:text-white">{{ $material->title }}</h3>
                            @if($material->description)
                                <p class="text-sm text-gray-600 dark:text-gray-400 mt-1 line-clamp-2">{{ $material->description }}</p>
                            @endif
                            @if($material->duration)
                                <div class="text-xs text-gray-500 mt-2">{{ gmdate('i:s', $material->duration) }}</div>
                            @endif
                        </div>
                        <div class="text-xs px-3 py-1 rounded-full bg-gray-100 dark:bg-white/10 text-gray-600 dark:text-gray-400 self-start">
                            {{ ucfirst($material->type) }}
                        </div>
                    </div>
                </a>
            @endforeach
        </div>
    @endif
</div>
@endsection
