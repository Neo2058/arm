@extends('teaching.layouts.index')

@section('content')
    <div class="max-w-3xl mx-auto w-full">
        <h1 class="text-2xl font-semibold mb-2">Уведомления</h1>
        <p class="text-sm text-[#706f6c] dark:text-[#A1A09A] mb-6">Сообщения чата, записи в формуляр и поручения. Текст переписки в оповещение не попадает.</p>
        <p class="text-sm mb-4">Непрочитанных: <strong id="arm-notify-page-unread">{{ $inbox['unread'] }}</strong></p>
        <form method="post" action="{{ route('notifications.read-all') }}" class="mb-6">
            @csrf
            <button type="submit" class="px-4 py-2 rounded-xl bg-orange-500 text-white text-sm font-semibold">Отметить все прочитанными</button>
        </form>
        <ul class="space-y-3">
            @forelse ($inbox['items'] as $item)
                <li class="rounded-2xl border border-black/10 dark:border-white/10 p-4 {{ $item['read_at'] ? 'opacity-60' : '' }}">
                    <div class="text-sm font-semibold">{{ $item['title'] }}</div>
                    <div class="text-xs text-[#706f6c] dark:text-[#A1A09A] mt-1">{{ $item['created_at'] }}</div>
                    @if ($item['type'] === 'chat_message')
                        <p class="text-sm mt-2">Откройте приложение ТЧ-15, чтобы прочитать сообщение в чате.</p>
                    @else
                        <a class="text-sm text-orange-500 mt-2 inline-block" href="{{ $item['href'] }}">Открыть</a>
                    @endif
                    @if (! $item['read_at'])
                        <form method="post" action="{{ route('notifications.read', $item['id']) }}" class="mt-3">
                            @csrf
                            <button type="submit" class="text-xs underline">Отметить прочитанным</button>
                        </form>
                    @endif
                </li>
            @empty
                <li class="text-sm text-[#706f6c]">Пока нет уведомлений.</li>
            @endforelse
        </ul>
    </div>
@endsection
