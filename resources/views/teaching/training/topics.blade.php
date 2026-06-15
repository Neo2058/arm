@extends('teaching.layouts.index')

@section('content')
<div class="max-w-4xl mx-auto w-full">
    <h1 class="text-3xl font-bold mb-6 text-gray-900 dark:text-white">Темы обучения</h1>
    <p class="text-gray-600 dark:text-gray-400 mb-8">Выберите тему для изучения материалов (видео, аудио, выжимки). Дублирует функционал Telegram-бота.</p>

    @if($topics->isEmpty())
        <div class="bg-white dark:bg-[#0b1018] border border-gray-200 dark:border-white/10 rounded-2xl p-8 text-center">
            <p class="text-gray-500">Пока нет доступных тем.</p>
        </div>
    @else
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            @foreach($topics as $topic)
                <a href="{{ route('training.topic', $topic->slug) }}"
                   class="block bg-white dark:bg-[#0b1018] border border-gray-200 dark:border-white/10 rounded-2xl p-6 hover:shadow-lg transition-all active:scale-[0.985]">
                    <h3 class="text-xl font-semibold text-gray-900 dark:text-white mb-2">{{ $topic->title }}</h3>
                    @if($topic->description)
                        <p class="text-sm text-gray-600 dark:text-gray-400 line-clamp-3">{{ $topic->description }}</p>
                    @endif
                    <div class="mt-4 text-xs text-orange-500 font-medium">Посмотреть материалы →</div>
                </a>
            @endforeach
        </div>
    @endif
</div>
@endsection
