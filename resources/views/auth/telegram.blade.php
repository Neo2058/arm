@extends('teaching.layouts.registry')

@section('content')
<main class="min-h-screen bg-[#0b1018] text-white flex items-center justify-center p-6">
    <section class="max-w-lg w-full rounded-3xl border border-white/10 bg-white/5 p-8 space-y-6">
        <h1 class="text-2xl font-bold">Telegram для получения ключа</h1>
        @if(session('success')) <p role="status">{{ session('success') }}</p> @endif
        @if($account)
            <p>Telegram привязан (ID {{ $account->telegram_user_id }}). Отправьте боту /key в личном чате.</p>
            <form method="POST" action="{{ route('telegram.link.destroy') }}">
                @csrf @method('DELETE')
                <button class="rounded-xl bg-red-600 px-5 py-3">Отвязать Telegram</button>
            </form>
        @elseif(!$available)
            <p>Бот пока не настроен. Обратитесь к администратору.</p>
        @else
            <p>Создайте личную ссылку, откройте её и нажмите «Запустить» в Telegram. Ссылка действует 10 минут. Не передавайте её другим людям.</p>
            <form method="POST" action="{{ route('telegram.link.issue') }}">
                @csrf
                <button class="rounded-xl bg-orange-600 px-5 py-3">Создать ссылку привязки</button>
            </form>
            @if(session('telegram_link_url'))
                <a class="block underline" href="{{ session('telegram_link_url') }}" target="_blank" rel="noreferrer noopener">Открыть моего бота в Telegram</a>
                <p>После привязки обновите эту страницу. Команда /key выдаст ключ на текущий месяц.</p>
            @endif
        @endif
        <p>После привязки команда /reset позволит восстановить пароль. Не отправляйте пароль сообщением боту.</p>
        <a class="block underline" href="{{ route('account.show') }}">Мой профиль</a>
        <a class="block underline" href="{{ route('barrier.show') }}">Вернуться к вводу ключа</a>
    </section>
</main>
@endsection
