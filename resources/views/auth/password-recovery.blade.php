<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Восстановление пароля — АРМ ТЧ-15</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background: #080b11; color: #e4e9f2; font: 16px/1.6 system-ui, sans-serif; }
        main { max-width: 540px; margin: 8vh auto; padding: 28px; }
        section { padding: 28px; border: 1px solid #23324a; border-radius: 28px; background: #101722; }
        h1 { color: #60a5fa; font-size: 28px; line-height: 1.3; }
        a { color: #79c9ff; }
        input { width: 100%; margin: 6px 0 18px; padding: 12px; border: 1px solid #52637c; border-radius: 10px; background: #080b11; color: white; font: inherit; }
        button, .button { display: inline-block; border: 0; padding: 12px 18px; border-radius: 12px; background: #2563eb; color: white; font: inherit; text-decoration: none; cursor: pointer; }
        :focus-visible { outline: 3px solid #67e8f9; outline-offset: 3px; }
        .error { color: #fca5a5; } .muted { color: #a1aec3; }
        @media (max-width: 540px) { main { margin: 16px auto; padding: 16px; } section { padding: 22px; } }
    </style>
</head>
<body>
<main><section>
    <h1>Восстановление пароля</h1>
    @if($success ?? false)
        <p role="status">Пароль изменён. Войдите с новым паролем на сайте и в приложении.</p>
    @else
        @if($failure ?? false)<p class="error" role="alert">{{ $failure }}</p>@endif
        @if($resetForm ?? false)
            <p>Придумайте новый пароль: не менее 12 символов, буквы и цифры, не более 72 байт.</p>
            <form method="POST" action="{{ route('password.update', [], false) }}" id="reset-form">
                @csrf
                <input type="hidden" name="token" id="reset-token" value="">
                <label for="password">Новый пароль</label>
                <input id="password" type="password" name="password" autocomplete="new-password" minlength="12" maxlength="72" required>
                <label for="password-confirmation">Повторите пароль</label>
                <input id="password-confirmation" type="password" name="password_confirmation" autocomplete="new-password" minlength="12" maxlength="72" required>
                <button type="submit" id="reset-submit" disabled>Сохранить пароль</button>
            </form>
            <p id="link-error" class="muted" hidden>Откройте полную ссылку из сообщения бота. Для новой ссылки отправьте /reset.</p>
            <noscript><p>Для открытия ссылки включите JavaScript в браузере.</p></noscript>
            <script>
                (() => {
                    const token = window.location.hash.slice(1);
                    history.replaceState(null, '', window.location.pathname);
                    if (/^[A-Za-z0-9]{64}$/.test(token)) {
                        document.getElementById('reset-token').value = token;
                        document.getElementById('reset-submit').disabled = false;
                    } else {
                        document.getElementById('link-error').hidden = false;
                    }
                })();
            </script>
        @else
            <p>Если вы заранее привязали Telegram к учётной записи, откройте бота и отправьте <strong>/reset</strong>.</p>
            @if($botUrl ?? false)<p><a class="button" href="{{ $botUrl }}" rel="noreferrer noopener">Открыть Telegram-бота</a></p>@endif
            <p class="muted">Ссылка действует 10 минут и используется один раз. Новый пароль вводится только на сайте, а не в сообщении боту.</p>
            <p class="muted">Если Telegram не привязан или доступ к нему утрачен, обратитесь к администратору.</p>
        @endif
    @endif
    <p><a href="{{ route('login', [], false) }}">Вернуться ко входу</a></p>
</section></main>
</body>
</html>
