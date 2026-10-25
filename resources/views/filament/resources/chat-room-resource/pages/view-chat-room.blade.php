<x-filament-panels::page>
    @if ($this->hasInfolist())
        {{ $this->infolist }}
    @endif

    @php($feed = $this->getFeed())

    <x-filament::section>
        <x-slot name="heading">Лента сообщений</x-slot>
        <x-slot name="description">Новые сверху, по 50. {{ \App\Filament\Resources\ChatRoomResource::SUBTITLE }}.</x-slot>

        <div class="divide-y divide-gray-200 dark:divide-white/10">
            @forelse ($feed as $message)
                <div class="py-3" wire:key="chat-msg-{{ $message->id }}">
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0">
                            <div class="text-sm text-gray-500 dark:text-gray-400">
                                {{ $message->sender?->name ?? 'Система' }}
                                ·
                                {{ $message->created_at?->timezone((string) config('chat.timezone', 'Europe/Moscow'))->format('d.m.Y H:i') }}
                                ·
                                {{ $message->type->value }}
                                @if ($message->status->value === 'rejected')
                                    · отклонено антивирусом
                                @endif
                                @if ($message->deleted_at)
                                    · удалено
                                @endif
                            </div>
                            <div class="mt-1 whitespace-pre-wrap break-words">
                                {{ $this->presentBody($message) }}
                            </div>
                        </div>
                        @if ($message->deleted_at === null && $message->status->value !== 'rejected')
                            <button
                                type="button"
                                class="text-sm text-danger-600 hover:underline"
                                wire:click="deleteMessage({{ $message->id }})"
                                wire:confirm="Удалить сообщение? Шифротекст в базе останется."
                            >
                                Удалить
                            </button>
                        @endif
                    </div>
                </div>
            @empty
                <p class="text-sm text-gray-500 dark:text-gray-400">Нет сообщений</p>
            @endforelse
        </div>

        <div class="mt-4">
            {{ $feed->links() }}
        </div>
    </x-filament::section>
</x-filament-panels::page>
