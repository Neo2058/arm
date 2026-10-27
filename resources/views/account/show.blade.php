<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Мой профиль — АРМ ТЧ-15</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background: #080b11; color: #e4e9f2; font: 16px/1.6 system-ui, sans-serif; }
        main { max-width: 1040px; margin: auto; padding: 36px 24px 64px; }
        a { color: #79c9ff; text-underline-offset: 4px; }
        a:focus-visible { outline: 3px solid #67e8f9; outline-offset: 5px; }
        header { margin: 36px 0 32px; }
        .eyebrow { color: #8799b4; font-size: 12px; letter-spacing: .15em; text-transform: uppercase; }
        h1 { margin: 8px 0; color: #60a5fa; font-size: clamp(32px, 6vw, 52px); line-height: 1.2; }
        h2 { font-size: 22px; margin: 0 0 12px; }
        p { margin: 0 0 16px; }
        input { width: 100%; padding: 10px; border: 1px solid #475569; border-radius: 8px; background: #080b11; color: #e4e9f2; }
        input[type="checkbox"] { width: auto; }
        label { display: block; margin: 12px 0; }
        button { border: 0; cursor: pointer; font: inherit; }
        .muted { color: #a1aec3; }
        .grid { display: grid; grid-template-columns: 1.4fr 1fr; gap: 24px; align-items: start; }
        section { border: 1px solid #23324a; border-radius: 28px; background: #101722; padding: 28px; }
        dl { margin: 20px 0 0; }
        dl div { padding: 14px 0; border-top: 1px solid #23324a; }
        dt { color: #a1aec3; font-size: 13px; }
        dd { margin: 4px 0 0; overflow-wrap: anywhere; }
        .status { display: inline-block; padding: 5px 12px; margin-bottom: 18px; border-radius: 20px; background: #1c2d43; color: #a7d8ff; font-size: 14px; }
        .linked { background: #12332d; color: #8ce2c0; }
        .button { display: inline-block; padding: 12px 20px; border-radius: 14px; background: #2563eb; color: white; text-decoration: none; font-weight: 600; }
        .button:hover { background: #1d4ed8; }
        .note { margin: 20px 0 0; font-size: 14px; color: #a1aec3; }
        @media (max-width: 700px) { main { padding: 24px 16px 40px; } .grid { grid-template-columns: 1fr; } section { padding: 24px; } }
    </style>
</head>
<body>
<main>
    <nav aria-label="Навигация"><a href="{{ route('mainMenu') }}">← Вернуться в главное меню</a></nav>
    <header>
        <span class="eyebrow">АРМ ТЧ-15 · Личный кабинет</span>
        <h1>Мой профиль</h1>
        <p class="muted">Ваши данные и связь с Telegram.</p>
    </header>
    <div class="grid">
        <section aria-labelledby="employee-heading">
            <h2 id="employee-heading">Данные сотрудника</h2>
            <p class="muted">Исправьте свои данные и сохраните изменения. Роль, колонну, инструктора и табельный номер назначает администратор.</p>
            @if(session('success'))<p role="status">{{ session('success') }}</p>@endif
            @if($errors->any())<ul role="alert">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>@endif
            <form method="POST" action="{{ route('account.update') }}">
                @csrf
                @method('PATCH')
                @foreach($selfProfile['fields'] as $field)
                    @php($key = $field['key'])
                    @if($field['type'] === 'windows')
                        <fieldset><legend>{{ $field['label'] }}</legend>
                            @for($i=0; $i<3; $i++)
                                <label>Время {{ $i+1 }}<input name="{{ $key }}[{{ $i }}][time]" maxlength="5" placeholder="ЧЧ.ММ" value="{{ old($key.'.'.$i.'.time', $selfProfile['values'][$key][$i]['time'] ?? '') }}"></label>
                                <label>Основание<input name="{{ $key }}[{{ $i }}][reason]" maxlength="8" value="{{ old($key.'.'.$i.'.reason', $selfProfile['values'][$key][$i]['reason'] ?? '') }}"></label>
                            @endfor
                        </fieldset>
                    @elseif($field['type'] === 'boolean')
                        <input type="hidden" name="{{ $key }}" value="0">
                        <label><input type="checkbox" name="{{ $key }}" value="1" @checked(old($key, $selfProfile['values'][$key]))> {{ $field['label'] }}</label>
                    @else
                        <label>{{ $field['label'] }}<input type="{{ $field['type'] }}" name="{{ $key }}" value="{{ old($key, $selfProfile['values'][$key]) }}" @if($field['max_length']) maxlength="{{ $field['max_length'] }}" @endif @if($field['type'] === 'number') min="0" max="999" @endif @required($key === 'full_name')></label>
                    @endif
                @endforeach
                <button class="button" type="submit">Сохранить профиль</button>
            </form>
            <dl>
                @foreach([
                    'ФИО' => $user->name,
                    'Email' => $user->email,
                    'Роль в системе' => $roleLabel,
                    'Должность' => $profile?->position,
                    'Колонна' => $profile?->column,
                    'Табельный номер' => $profile?->tab_number,
                ] as $label => $value)
                    <div><dt>{{ $label }}</dt><dd>{{ filled($value) ? $value : 'Не указано' }}</dd></div>
                @endforeach
            </dl>
        </section>
        <section aria-labelledby="telegram-heading">
            <h2 id="telegram-heading">Telegram</h2>
            <span class="status {{ $telegramLinked ? 'linked' : '' }}">{{ $telegramLinked ? 'Привязан' : 'Не привязан' }}</span>
            <p>{{ $telegramLinked ? 'Ваш Telegram связан с учётной записью. Для получения ключа на текущий месяц отправьте боту /key в личном чате.' : 'Привяжите личный Telegram, чтобы получать ключ доступа на текущий месяц у бота.' }}</p>
            <a class="button" href="{{ route('telegram.link.show') }}">{{ $telegramLinked ? 'Управлять привязкой' : 'Привязать Telegram' }}</a>
            <p class="note">Привязывайте только свой личный Telegram. Не передавайте другим ссылки привязки и ключи доступа.</p>
        </section>
    </div>
</main>
</body>
</html>
