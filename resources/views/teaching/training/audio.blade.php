@extends('teaching.layouts.index')

@section('content')
<div class="max-w-3xl mx-auto w-full">
    <a href="{{ route('training.topic', $material->topic->slug) }}" class="text-sm text-orange-500 hover:underline mb-4 inline-block">← Назад к теме</a>

    <div class="bg-white dark:bg-[#0b1018] border border-gray-200 dark:border-white/10 rounded-3xl overflow-hidden shadow">
        <div class="p-6 border-b border-gray-200 dark:border-white/10">
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ $material->title }}</h1>
            @if($material->description)
                <p class="mt-2 text-gray-600 dark:text-gray-400">{{ $material->description }}</p>
            @endif
        </div>

        <!-- Audio Player - Mobile First, responsive -->
        <div class="p-6">
            <audio 
                class="w-full" 
                controls 
                preload="metadata"
            >
                <source src="{{ $material->file_url }}" type="{{ $material->mime_type ?? 'audio/mpeg' }}">
                Ваш браузер не поддерживает воспроизведение аудио.
            </audio>

            @if($material->duration)
                <div class="text-xs text-gray-500 mt-2 text-right">Длительность: {{ gmdate('i:s', $material->duration) }}</div>
            @endif
        </div>

        <div class="p-6 border-t border-gray-200 dark:border-white/10">
            <!-- Reactions -->
            <div class="flex items-center gap-4 mb-6">
                <form action="{{ route('training.reaction.store', $material) }}" method="POST" class="flex items-center gap-2">
                    @csrf
                    <input type="hidden" name="type" value="like">
                    <button type="submit" 
                            class="flex items-center gap-1 px-4 py-2 rounded-xl border transition {{ $userReaction === 'like' ? 'bg-green-100 border-green-300 text-green-700 dark:bg-green-900/30' : 'hover:bg-gray-100 dark:hover:bg-white/5 border-gray-200 dark:border-white/10' }}">
                        👍 <span>{{ $likesCount }}</span>
                    </button>
                </form>

                <form action="{{ route('training.reaction.store', $material) }}" method="POST" class="flex items-center gap-2">
                    @csrf
                    <input type="hidden" name="type" value="dislike">
                    <button type="submit" 
                            class="flex items-center gap-1 px-4 py-2 rounded-xl border transition {{ $userReaction === 'dislike' ? 'bg-red-100 border-red-300 text-red-700 dark:bg-red-900/30' : 'hover:bg-gray-100 dark:hover:bg-white/5 border-gray-200 dark:border-white/10' }}">
                        👎 <span>{{ $dislikesCount }}</span>
                    </button>
                </form>
            </div>

            <!-- Comments -->
            <div>
                <h3 class="font-semibold mb-3 text-gray-900 dark:text-white">Комментарии</h3>

                @auth
                    <form action="{{ route('training.comment.store', $material) }}" method="POST" class="mb-6">
                        @csrf
                        <div class="flex gap-2">
                            <input type="text" name="comment" required maxlength="1000" placeholder="Ваш комментарий..." 
                                   class="flex-1 px-4 py-2 rounded-xl border border-gray-200 dark:border-white/10 bg-white dark:bg-black/20 text-sm focus:outline-none">
                            <button type="submit" class="px-5 py-2 bg-orange-500 text-white rounded-xl text-sm font-medium active:scale-95">Отправить</button>
                        </div>
                    </form>
                @else
                    <p class="text-sm text-gray-500 mb-4">Войдите, чтобы оставить комментарий.</p>
                @endauth

                @forelse($comments as $comment)
                    <div class="border-t border-gray-100 dark:border-white/10 py-4">
                        <div class="flex items-center gap-2 text-sm mb-1">
                            <span class="font-medium">{{ $comment->user->name ?? 'Пользователь' }}</span>
                            <span class="text-gray-400 text-xs">{{ $comment->created_at->diffForHumans() }}</span>
                        </div>
                        <p class="text-sm text-gray-700 dark:text-gray-300">{{ $comment->comment }}</p>
                    </div>
                @empty
                    <p class="text-sm text-gray-500">Комментариев пока нет. Будьте первым!</p>
                @endforelse
            </div>
        </div>
    </div>
</div>
@endsection
